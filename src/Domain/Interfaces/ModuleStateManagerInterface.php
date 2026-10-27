<?php

declare(strict_types=1);

namespace Flexi\Domain\Interfaces;

use Flexi\Domain\ValueObjects\ModuleState;
use Flexi\Domain\ValueObjects\ModuleType;

/**
 * Interface for managing the activation state of modules.
 *
 * Provides methods to activate/deactivate modules, query their status,
 * and persist state changes regardless of the module installation type.
 */
interface ModuleStateManagerInterface
{
    /**
     * Check if a module is currently active.
     */
    public function isModuleActive(string $moduleName): bool;

    /**
     * Activate a module.
     */
    public function activateModule(string $moduleName, ?string $modifiedBy = null): bool;

    /**
     * Deactivate a module.
     */
    public function deactivateModule(string $moduleName, ?string $modifiedBy = null): bool;

    /**
     * Get the state of a specific module.
     */
    public function getModuleState(string $moduleName): ?ModuleState;

    /**
     * Get all active modules.
     *
     * @return string[] Array of active module names
     */
    public function getActiveModules(): array;

    /**
     * Get all inactive modules.
     *
     * @return string[] Array of inactive module names
     */
    public function getInactiveModules(): array;

    /**
     * Get all known modules (active and inactive).
     *
     * @return string[] Array of all known module names
     */
    public function getAllKnownModules(): array;

    /**
     * Get states of all modules.
     *
     * @return ModuleState[] Array of module states indexed by module name
     */
    public function getAllModuleStates(): array;

    /**
     * Set the state of a module.
     */
    public function setModuleState(ModuleState $state): bool;

    /**
     * Update module type (useful for migration scenarios).
     */
    public function updateModuleType(string $moduleName, ModuleType $type, ?string $modifiedBy = null): bool;

    /**
     * Initialize state for a newly discovered module.
     */
    public function initializeModuleState(
        string $moduleName,
        ModuleType $type,
        bool $active = false,
        ?string $modifiedBy = null
    ): bool;

    /**
     * Remove a module from state management.
     */
    public function removeModuleState(string $moduleName): bool;

    /**
     * Bulk activate multiple modules.
     *
     * @param string[] $moduleNames
     * @return array Results array with success/failure for each module
     */
    public function activateModules(array $moduleNames, ?string $modifiedBy = null): array;

    /**
     * Bulk deactivate multiple modules.
     *
     * @param string[] $moduleNames
     * @return array Results array with success/failure for each module
     */
    public function deactivateModules(array $moduleNames, ?string $modifiedBy = null): array;

    /**
     * Synchronize state with discovered modules.
     * Adds new modules, removes deleted modules, updates types.
     *
     * @param array $discoveredModules Array of ModuleInfo objects
     * @return array Summary of synchronization actions
     */
    public function syncWithDiscoveredModules(array $discoveredModules): array;

    /**
     * Clear all state data (useful for testing or complete reset).
     */
    public function clearAllStates(): bool;

    /**
     * Export state data for backup or migration.
     */
    public function exportStates(): array;

    /**
     * Import state data from backup or migration.
     */
    public function importStates(array $statesData, bool $overwrite = false): array;
}
