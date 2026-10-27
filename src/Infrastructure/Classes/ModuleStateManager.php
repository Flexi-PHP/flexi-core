<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleState;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;

/**
 * Manages the activation state of modules.
 *
 * Provides a high-level interface for module state operations,
 * using ModuleStateRepository for persistence.
 */
class ModuleStateManager implements ModuleStateManagerInterface
{
    private ModuleStateRepository $repository;

    public function __construct(ModuleStateRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Check if a module is currently active.
     */
    public function isModuleActive(string $moduleName): bool
    {
        $state = $this->repository->find($moduleName);
        return $state ? $state->isActive() : false;
    }

    /**
     * Activate a module.
     */
    public function activateModule(string $moduleName, ?string $modifiedBy = null): bool
    {
        $existingState = $this->repository->find($moduleName);

        if ($existingState) {
            $newState = $existingState->activate($modifiedBy ?? 'system');
        } else {
            $newState = ModuleState::createActive($moduleName, ModuleType::local())
                ->withMetadata(['modifiedBy' => $modifiedBy ?? 'system']);
        }

        return $this->repository->save($newState);
    }

    /**
     * Deactivate a module.
     */
    public function deactivateModule(string $moduleName, ?string $modifiedBy = null): bool
    {
        $existingState = $this->repository->find($moduleName);

        if ($existingState) {
            $newState = $existingState->deactivate($modifiedBy ?? 'system');
        } else {
            $newState = ModuleState::createInactive($moduleName, ModuleType::local())
                ->withMetadata(['modifiedBy' => $modifiedBy ?? 'system']);
        }

        return $this->repository->save($newState);
    }

    /**
     * Get the state of a specific module.
     */
    public function getModuleState(string $moduleName): ?ModuleState
    {
        return $this->repository->find($moduleName);
    }

    /**
     * Get all active modules.
     */
    public function getActiveModules(): array
    {
        $allStates = $this->repository->findAll();
        $activeModules = [];

        foreach ($allStates as $moduleName => $state) {
            if ($state->isActive()) {
                $activeModules[] = $moduleName;
            }
        }

        return $activeModules;
    }

    /**
     * Get all inactive modules.
     */
    public function getInactiveModules(): array
    {
        $allStates = $this->repository->findAll();
        $inactiveModules = [];

        foreach ($allStates as $moduleName => $state) {
            if (!$state->isActive()) {
                $inactiveModules[] = $moduleName;
            }
        }

        return $inactiveModules;
    }

    /**
     * Get all known modules (active and inactive).
     */
    public function getAllKnownModules(): array
    {
        return array_keys($this->repository->findAll());
    }

    /**
     * Get states of all modules.
     */
    public function getAllModuleStates(): array
    {
        return $this->repository->findAll();
    }

    /**
     * Set the state of a module.
     */
    public function setModuleState(ModuleState $state): bool
    {
        return $this->repository->save($state);
    }

    /**
     * Update module type (useful for migration scenarios).
     */
    public function updateModuleType(string $moduleName, ModuleType $type, ?string $modifiedBy = null): bool
    {
        $existingState = $this->repository->find($moduleName);

        if ($existingState) {
            $newState = $existingState->withType($type, $modifiedBy ?? 'system');
        } else {
            $newState = ModuleState::createActive($moduleName, $type)
                ->withMetadata(['modifiedBy' => $modifiedBy ?? 'system']);
        }

        return $this->repository->save($newState);
    }

    /**
     * Initialize state for a newly discovered module.
     */
    public function initializeModuleState(
        string $moduleName,
        ModuleType $type,
        bool $active = false,
        ?string $modifiedBy = null
    ): bool {
        // Don't overwrite existing state
        if ($this->repository->exists($moduleName)) {
            // Just update the type if it changed
            $existingState = $this->repository->find($moduleName);
            if ($existingState && !$existingState->getType()->equals($type)) {
                return $this->updateModuleType($moduleName, $type, $modifiedBy);
            }
            return true; // State already exists, no need to initialize
        }

        $state = $active
            ? ModuleState::createActive($moduleName, $type)
            : ModuleState::createInactive($moduleName, $type);

        $state = $state->withMetadata(['modifiedBy' => $modifiedBy ?? 'system']);

        return $this->repository->save($state);
    }

    /**
     * Remove a module from state management.
     */
    public function removeModuleState(string $moduleName): bool
    {
        return $this->repository->delete($moduleName);
    }

    /**
     * Bulk activate multiple modules.
     */
    public function activateModules(array $moduleNames, ?string $modifiedBy = null): array
    {
        $results = [];

        foreach ($moduleNames as $moduleName) {
            $results[$moduleName] = [
                'success' => $this->activateModule($moduleName, $modifiedBy),
                'action' => 'activate'
            ];
        }

        return $results;
    }

    /**
     * Bulk deactivate multiple modules.
     */
    public function deactivateModules(array $moduleNames, ?string $modifiedBy = null): array
    {
        $results = [];

        foreach ($moduleNames as $moduleName) {
            $results[$moduleName] = [
                'success' => $this->deactivateModule($moduleName, $modifiedBy),
                'action' => 'deactivate'
            ];
        }

        return $results;
    }

    /**
     * Synchronize state with discovered modules.
     */
    public function syncWithDiscoveredModules(array $discoveredModules): array
    {
        $summary = [
            'initialized' => 0,
            'updated' => 0,
            'removed' => 0,
            'unchanged' => 0,
            'errors' => [],
            'actions' => []
        ];

        $discoveredNames = [];

        // Process discovered modules
        foreach ($discoveredModules as $moduleInfo) {
            $moduleName = $moduleInfo->getName();
            $discoveredNames[] = $moduleName;

            $existingState = $this->repository->find($moduleName);

            if (!$existingState) {
                // Newly discovered modules require an explicit activation decision.
                if ($this->initializeModuleState($moduleName, $moduleInfo->getType(), false, 'sync')) {
                    $summary['initialized']++;
                    $summary['actions'][$moduleName] = 'initialized';
                } else {
                    $summary['errors'][$moduleName] = 'Failed to initialize';
                }
            } else {
                // Existing module - check if type changed
                if (!$existingState->getType()->equals($moduleInfo->getType())) {
                    if ($this->updateModuleType($moduleName, $moduleInfo->getType(), 'sync')) {
                        $summary['updated']++;
                        $summary['actions'][$moduleName] = 'type_updated';
                    } else {
                        $summary['errors'][$moduleName] = 'Failed to update type';
                    }
                } else {
                    $summary['unchanged']++;
                    $summary['actions'][$moduleName] = 'unchanged';
                }
            }
        }

        // Remove states for modules that no longer exist
        $existingStates = $this->repository->findAll();
        foreach (array_keys($existingStates) as $existingModuleName) {
            if (!in_array($existingModuleName, $discoveredNames)) {
                if ($this->removeModuleState($existingModuleName)) {
                    $summary['removed']++;
                    $summary['actions'][$existingModuleName] = 'removed';
                } else {
                    $summary['errors'][$existingModuleName] = 'Failed to remove';
                }
            }
        }

        return $summary;
    }

    /**
     * Clear all state data.
     */
    public function clearAllStates(): bool
    {
        return $this->repository->clear();
    }

    /**
     * Export state data for backup or migration.
     */
    public function exportStates(): array
    {
        return $this->repository->exportData();
    }

    /**
     * Import state data from backup or migration.
     */
    public function importStates(array $statesData, bool $overwrite = false): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'imported_count' => 0
        ];

        if ($this->repository->importData($statesData, $overwrite)) {
            $result['success'] = true;
            $result['message'] = 'State data imported successfully';
            $result['imported_count'] = count($statesData['modules'] ?? []);
        } else {
            $result['message'] = 'Failed to import state data';
        }

        return $result;
    }

    /**
     * Get repository metadata and statistics.
     */
    public function getStatistics(): array
    {
        $metadata = $this->repository->getMetadata();
        $activeCount = count($this->getActiveModules());
        $inactiveCount = count($this->getInactiveModules());

        return array_merge($metadata, [
            'activeModules' => $activeCount,
            'inactiveModules' => $inactiveCount,
            'totalModules' => $activeCount + $inactiveCount
        ]);
    }

    /**
     * Backup current state data.
     */
    public function backup(): bool
    {
        return $this->repository->backup();
    }
}
