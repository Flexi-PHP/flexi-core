<?php

declare(strict_types=1);

namespace Flexi\Application\UseCase;

use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\Interfaces\ModuleEnvironmentManagerInterface;
use Flexi\Application\Services\ModuleActivationPreflightInterface;
use Flexi\Domain\Events\ModuleActivatedEvent;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Contracts\Classes\PlainTextMessage;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;

/**
 * Use case for activating a module.
 *
 * Handles the business logic for activating a specific module,
 * including validation and state management.
 */
class ActivateModule implements HandlerInterface
{
    private ModuleStateManagerInterface $stateManager;
    private HybridModuleDetector $moduleDetector;
    private ModuleEnvironmentManagerInterface $envManager;
    private ModuleActivationPreflightInterface $preflight;
    private EventBusInterface $eventBus;

    public function __construct(
        ModuleStateManagerInterface $stateManager,
        HybridModuleDetector $moduleDetector,
        ModuleEnvironmentManagerInterface $envManager,
        ModuleActivationPreflightInterface $preflight,
        EventBusInterface $eventBus
    ) {
        $this->stateManager = $stateManager;
        $this->moduleDetector = $moduleDetector;
        $this->envManager = $envManager;
        $this->preflight = $preflight;
        $this->eventBus = $eventBus;
    }

    /**
     * Handle the activate module command.
     *
     * @param DTOInterface $dto ActivateModuleCommand
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

        // Check if module is already active
        if ($this->stateManager->isModuleActive($moduleName)) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Module '{$moduleName}' is already active",
                'module' => $moduleName,
                'status' => 'already_active'
            ]));
        }

        try {
            $this->preflight->validate($moduleInfo);
        } catch (\Throwable $exception) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Module '{$moduleName}' failed activation preflight: " . $exception->getMessage(),
                'module' => $moduleName,
                'status' => 'preflight_failed'
            ], JSON_PRETTY_PRINT));
        }

        if (
            $this->stateManager->getModuleState($moduleName) === null
            && !$this->stateManager->initializeModuleState($moduleName, $moduleInfo->getType(), false, $modifiedBy)
        ) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Failed to initialize inactive state for module '{$moduleName}'",
                'module' => $moduleName
            ], JSON_PRETTY_PRINT));
        }

        // Initialize module state if it doesn't exist, or just activate it
        $success = $this->stateManager->activateModule($moduleName, $modifiedBy);

        if (!$success) {
            return new PlainTextMessage(json_encode([
                'success' => false,
                'error' => "Failed to activate module '{$moduleName}'",
                'module' => $moduleName
            ]));
        }

        // Handle module environment variables if present
        $envWarnings = [];
        if ($this->envManager->hasModuleEnvFile($moduleInfo->getPath())) {
            $moduleEnvVars = $this->envManager->readModuleEnvironment($moduleInfo->getPath(), $moduleName);
            if (!empty($moduleEnvVars)) {
                // Check if the module already has environment variables integrated
                if ($this->envManager->hasModuleEnvironment($moduleName)) {
                    // Update existing environment variables, preserving user modifications
                    $envSuccess = $this->envManager->updateModuleEnvironment($moduleName, $moduleEnvVars);
                } else {
                    // Add new environment variables
                    $envSuccess = $this->envManager->addModuleEnvironment($moduleName, $moduleEnvVars);
                }

                if (!$envSuccess) {
                    $envWarnings[] = "Failed to integrate module environment variables with main .env file";
                }
            }
        }

        // Get updated state for response
        $moduleState = $this->stateManager->getModuleState($moduleName);

        $response = [
            'success' => true,
            'message' => "Module '{$moduleName}' has been activated successfully",
            'module' => $moduleName,
            'status' => 'activated',
            'details' => [
                'type' => $moduleInfo->getType()->getValue(),
                'path' => $moduleInfo->getPath(),
                'package' => $moduleInfo->getPackage(),
                'version' => $moduleInfo->getVersion(),
                'last_modified' => $moduleState ? $moduleState->getLastModified()->format('Y-m-d H:i:s') : null,
                'modified_by' => $modifiedBy,
                'has_env_file' => $this->envManager->hasModuleEnvFile($moduleInfo->getPath()),
                'env_vars_integrated' => $this->envManager->hasModuleEnvironment($moduleName)
            ]
        ];

        // Add environment warnings if any
        if (!empty($envWarnings)) {
            $response['env_warnings'] = $envWarnings;
        }

        try {
            $this->eventBus->dispatch(new ModuleActivatedEvent($moduleInfo, $modifiedBy));
        } catch (\Throwable $exception) {
            $response['event_warnings'][] = 'Activation succeeded, but a lifecycle listener failed: ' . $exception->getMessage();
        }

        // Add conflict warning if applicable
        if ($moduleInfo->hasConflict()) {
            $response['warning'] = "Module exists in multiple locations. Using: " . $moduleInfo->getPath();
            $response['details']['conflict'] = true;
            $response['details']['local_path'] = $moduleInfo->getMetadataValue('local_path');
            $response['details']['vendor_path'] = $moduleInfo->getMetadataValue('vendor_path');
        }

        return new PlainTextMessage(json_encode($response, JSON_PRETTY_PRINT));
    }
}
