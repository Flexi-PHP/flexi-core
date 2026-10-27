<?php

declare(strict_types=1);

namespace Flexi\Application\UseCase;

use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\Interfaces\ModuleEnvironmentManagerInterface;
use Flexi\Domain\Events\ModuleDeactivatedEvent;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Contracts\Classes\PlainTextMessage;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;

/**
 * Use case for deactivating a module.
 *
 * Handles the business logic for deactivating a specific module,
 * including validation and state management.
 */
class DeactivateModule implements HandlerInterface
{
    private ModuleStateManagerInterface $stateManager;
    private HybridModuleDetector $moduleDetector;
    private ModuleEnvironmentManagerInterface $envManager;
    private EventBusInterface $eventBus;

    public function __construct(
        ModuleStateManagerInterface $stateManager,
        HybridModuleDetector $moduleDetector,
        ModuleEnvironmentManagerInterface $envManager,
        EventBusInterface $eventBus
    ) {
        $this->stateManager = $stateManager;
        $this->moduleDetector = $moduleDetector;
        $this->envManager = $envManager;
        $this->eventBus = $eventBus;
    }

    /**
     * Handle the deactivate module command.
     *
     * @param DTOInterface $dto DeactivateModuleCommand
     * @return MessageInterface
     */
    public function handle(DTOInterface $dto): MessageInterface
    {
        $moduleName = $dto->get('module_name');
        $modifiedBy = $dto->get('modified_by') ?? 'user';

        // Validate input
        if (empty($moduleName)) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => 'Module name is required',
                'module' => null
            ]));
        }

        // Check if module exists (is discoverable)
        $moduleInfo = $this->moduleDetector->getModuleInfo($moduleName);
        if (!$moduleInfo) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Module '{$moduleName}' not found. Use 'modules:list' to see available modules.",
                'module' => $moduleName
            ]));
        }

        // Check if module is already inactive
        if (!$this->stateManager->isModuleActive($moduleName)) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Module '{$moduleName}' is already inactive",
                'module' => $moduleName,
                'status' => 'already_inactive'
            ]));
        }

        // Deactivate the module
        $success = $this->stateManager->deactivateModule($moduleName, $modifiedBy);

        if (!$success) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Failed to deactivate module '{$moduleName}'",
                'module' => $moduleName
            ]));
        }

        // Handle module environment variables removal
        $envWarnings = [];
        if ($this->envManager->hasModuleEnvironment($moduleName)) {
            $envSuccess = $this->envManager->removeModuleEnvironment($moduleName);
            if (!$envSuccess) {
                $envWarnings[] = "Failed to remove module environment variables from main .env file";
            }
        }

        // Get updated state for response
        $moduleState = $this->stateManager->getModuleState($moduleName);

        $response = [
            'success' => true,
            'message' => "Module '{$moduleName}' has been deactivated successfully",
            'module' => $moduleName,
            'status' => 'deactivated',
            'details' => [
                'type' => $moduleInfo->getType()->getValue(),
                'path' => $moduleInfo->getPath(),
                'package' => $moduleInfo->getPackage(),
                'version' => $moduleInfo->getVersion(),
                'last_modified' => $moduleState ? $moduleState->getLastModified()->format('Y-m-d H:i:s') : null,
                'modified_by' => $modifiedBy,
                'had_env_file' => $this->envManager->hasModuleEnvFile($moduleInfo->getPath()),
                'env_vars_removed' => !$this->envManager->hasModuleEnvironment($moduleName)
            ]
        ];

        // Add environment warnings if any
        if (!empty($envWarnings)) {
            $response['env_warnings'] = $envWarnings;
        }

        try {
            $this->eventBus->dispatch(new ModuleDeactivatedEvent($moduleInfo, $modifiedBy));
        } catch (\Throwable $exception) {
            $response['event_warnings'][] = 'Deactivation succeeded, but a lifecycle listener failed: ' . $exception->getMessage();
        }

        // Add conflict info if applicable
        if ($moduleInfo->hasConflict()) {
            $response['info'] = "Module exists in multiple locations. Deactivated from: " . $moduleInfo->getPath();
            $response['details']['conflict'] = true;
            $response['details']['local_path'] = $moduleInfo->getMetadataValue('local_path');
            $response['details']['vendor_path'] = $moduleInfo->getMetadataValue('vendor_path');
        }

        return new PlainTextMessage(json_encode($response, JSON_PRETTY_PRINT));
    }
}
