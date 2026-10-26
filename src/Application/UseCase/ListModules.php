<?php

declare(strict_types=1);

namespace Flexi\Application\UseCase;

use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Contracts\Classes\PlainTextMessage;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;

/**
 * Use case for listing all installed Flexi modules with their details.
 */
class ListModules implements HandlerInterface
{
    private ModuleStateManagerInterface $stateManager;
    private ModuleDetectorInterface $moduleDetector;

    public function __construct(
        ModuleStateManagerInterface $stateManager,
        ModuleDetectorInterface $moduleDetector
    ) {
        $this->stateManager = $stateManager;
        $this->moduleDetector = $moduleDetector;
    }

    /**
     * Handle the list modules command.
     *
     * @param DTOInterface $dto
     * @return MessageInterface
     * @throws \JsonException
     */
    public function handle(DTOInterface $dto): MessageInterface
    {
        $allModules = $this->moduleDetector->getAllModules();
        $this->stateManager->syncWithDiscoveredModules($allModules);
        // Get module statistics if available (some detectors may not implement this)
        $moduleStats = method_exists($this->moduleDetector, 'getModuleStatistics')
            ? $this->moduleDetector->getModuleStatistics()
            : [];

        $result = [
            'total' => count($allModules),
            'active' => 0,
            'inactive' => 0,
            'types' => $moduleStats,
            'modules' => [],
        ];

        foreach ($allModules as $moduleInfo) {
            $isActive = $this->stateManager->isModuleActive($moduleInfo->getName());
            $moduleState = $this->stateManager->getModuleState($moduleInfo->getName());

            if ($isActive) {
                $result['active']++;
            } else {
                $result['inactive']++;
            }

            $module_info = [
                'name' => $moduleInfo->getName(),
                'package' => $moduleInfo->getPackage(),
                'version' => $moduleInfo->getVersion(),
                'type' => $moduleInfo->getType()->getValue(),
                'path' => $moduleInfo->getPath(),
                'active' => $isActive,
                'description' => $moduleInfo->getMetadataValue('description', ''),
                'last_modified' => $moduleState ? $moduleState->getLastModified()->format('Y-m-d H:i:s') : null,
                'modified_by' => $moduleState ? $moduleState->getModifiedBy() : null,
            ];

            // Add conflict information if present
            if ($moduleInfo->hasConflict()) {
                $module_info['conflict'] = [
                    'has_conflict' => true,
                    'local_path' => $moduleInfo->getMetadataValue('local_path'),
                    'vendor_path' => $moduleInfo->getMetadataValue('vendor_path'),
                    'resolution_strategy' => $moduleInfo->getMetadataValue('resolution_strategy'),
                ];
            }

            // Add metadata if available
            $metadata = $moduleInfo->getMetadata();
            if (!empty($metadata)) {
                $module_info['metadata'] = $metadata;
            }

            $result['modules'][$moduleInfo->getName()] = $module_info;
        }

        // Sort modules by name for consistent output
        ksort($result['modules']);

        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        return new PlainTextMessage($json);
    }
}
