<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;

/**
 * Hybrid module detector that combines local and vendor module detection.
 *
 * Provides a unified view of all modules regardless of their installation method,
 * handles conflicts when modules exist in both locations, and provides resolution strategies.
 * Uses intelligent caching for performance optimization.
 */
class HybridModuleDetector implements ModuleDetectorInterface
{
    private LocalModuleDetector $localDetector;
    private VendorModuleDetector $vendorDetector;

    public function __construct(
        LocalModuleDetector $localDetector,
        VendorModuleDetector $vendorDetector
    ) {
        $this->localDetector = $localDetector;
        $this->vendorDetector = $vendorDetector;
    }

    /**
     * Check if a module is installed in any location.
     */
    public function isModuleInstalled(string $moduleName): bool
    {
        return $this->localDetector->isModuleInstalled($moduleName) ||
               $this->vendorDetector->isModuleInstalled($moduleName);
    }

    /**
     * Get all modules from both local and vendor sources.
     *
     * @return ModuleInfo[]
     */
    public function getAllModules(): array
    {
        $localModules = $this->localDetector->getAllModules();
        $vendorModules = $this->vendorDetector->getAllModules();

        return $this->mergeModules($localModules, $vendorModules);
    }

    /**
     * Get information about a specific module from any location.
     */
    public function getModuleInfo(string $moduleName): ?ModuleInfo
    {
        $localModule = $this->localDetector->getModuleInfo($moduleName);
        $vendorModule = $this->vendorDetector->getModuleInfo($moduleName);

        if ($localModule && $vendorModule) {
            return $this->sameSource($localModule, $vendorModule)
                ? $localModule
                : $this->createConflictModule($localModule, $vendorModule);
        }

        return $localModule ?? $vendorModule;
    }

    /**
     * Get modules that have conflicts (exist in both locations).
     *
     * @return ModuleInfo[]
     */
    public function getConflictedModules(): array
    {
        $conflicts = [];
        $localModules = $this->localDetector->getAllModules();
        $vendorModules = $this->vendorDetector->getAllModules();

        foreach ($localModules as $moduleName => $localModule) {
            if (isset($vendorModules[$moduleName]) && !$this->sameSource($localModule, $vendorModules[$moduleName])) {
                $conflicts[$moduleName] = $this->createConflictModule(
                    $localModule,
                    $vendorModules[$moduleName]
                );
            }
        }

        return $conflicts;
    }

    /**
     * Get modules only from local installation.
     *
     * @return ModuleInfo[]
     */
    public function getLocalOnlyModules(): array
    {
        $localModules = $this->localDetector->getAllModules();
        $vendorModules = $this->vendorDetector->getAllModules();

        $localOnly = [];
        foreach ($localModules as $name => $module) {
            if (!isset($vendorModules[$name]) || $this->sameSource($module, $vendorModules[$name])) {
                $localOnly[$name] = $module;
            }
        }

        return $localOnly;
    }

    /**
     * Get modules only from vendor installation.
     *
     * @return ModuleInfo[]
     */
    public function getVendorOnlyModules(): array
    {
        $localModules = $this->localDetector->getAllModules();
        $vendorModules = $this->vendorDetector->getAllModules();

        $vendorOnly = [];
        foreach ($vendorModules as $name => $module) {
            if (!isset($localModules[$name])) {
                $vendorOnly[$name] = $module;
            }
        }

        return $vendorOnly;
    }

    /**
     * Get module installation type.
     */
    public function getModuleInstallationType(string $moduleName): ?ModuleType
    {
        $isLocal = $this->localDetector->isModuleInstalled($moduleName);
        $isVendor = $this->vendorDetector->isModuleInstalled($moduleName);

        if ($isLocal && $isVendor) {
            $local = $this->localDetector->getModuleInfo($moduleName);
            $vendor = $this->vendorDetector->getModuleInfo($moduleName);
            return $local !== null && $vendor !== null && $this->sameSource($local, $vendor)
                ? ModuleType::local()
                : ModuleType::mixed();
        } elseif ($isLocal) {
            return ModuleType::local();
        } elseif ($isVendor) {
            return ModuleType::vendor();
        }

        return null;
    }

    /**
     * Check if a module has conflicts.
     */
    public function hasModuleConflict(string $moduleName): bool
    {
        if (!$this->localDetector->isModuleInstalled($moduleName) || !$this->vendorDetector->isModuleInstalled($moduleName)) {
            return false;
        }
        $local = $this->localDetector->getModuleInfo($moduleName);
        $vendor = $this->vendorDetector->getModuleInfo($moduleName);

        return $local !== null && $vendor !== null && !$this->sameSource($local, $vendor);
    }

    /**
     * Get statistics about module distribution.
     */
    public function getModuleStatistics(): array
    {
        $localModules = $this->localDetector->getAllModules();
        $vendorModules = $this->vendorDetector->getAllModules();
        $conflictedModules = $this->getConflictedModules();

        return [
            'total' => count($this->getAllModules()),
            'local_only' => count($this->getLocalOnlyModules()),
            'vendor_only' => count($this->getVendorOnlyModules()),
            'conflicts' => count($conflictedModules),
            'local_total' => count($localModules),
            'vendor_total' => count($vendorModules),
        ];
    }

    /**
     * Merge modules from local and vendor sources, handling conflicts.
     */
    private function mergeModules(array $localModules, array $vendorModules): array
    {
        $merged = [];

        // Add all local modules
        foreach ($localModules as $moduleName => $localModule) {
            if (isset($vendorModules[$moduleName])) {
                $merged[$moduleName] = $this->sameSource($localModule, $vendorModules[$moduleName])
                    ? $localModule
                    : $this->createConflictModule($localModule, $vendorModules[$moduleName]);
            } else {
                // Local only
                $merged[$moduleName] = $localModule;
            }
        }

        // Add vendor-only modules
        foreach ($vendorModules as $moduleName => $vendorModule) {
            if (!isset($localModules[$moduleName])) {
                $merged[$moduleName] = $vendorModule;
            }
        }

        return $merged;
    }

    private function sameSource(ModuleInfo $left, ModuleInfo $right): bool
    {
        $leftPath = realpath($left->getPath());
        $rightPath = realpath($right->getPath());

        return false !== $leftPath && false !== $rightPath && $leftPath === $rightPath;
    }

    /**
     * Create a module info object representing a conflict situation.
     */
    private function createConflictModule(ModuleInfo $localModule, ModuleInfo $vendorModule): ModuleInfo
    {
        // Priority: local path, but include metadata about conflict
        $conflictMetadata = array_merge($localModule->getMetadata(), [
            'conflict' => true,
            'local_path' => $localModule->getPath(),
            'vendor_path' => $vendorModule->getPath(),
            'local_version' => $localModule->getVersion(),
            'vendor_version' => $vendorModule->getVersion(),
            'resolution_strategy' => 'local_priority', // Default strategy
        ]);

        return new ModuleInfo(
            $localModule->getName(),
            $localModule->getPackage(),
            ModuleType::mixed(),
            $localModule->getPath(), // Prioritize local path
            $localModule->getVersion() ?? $vendorModule->getVersion(),
            $localModule->isActive(),
            $conflictMetadata
        );
    }

    /**
     * Resolve a conflict by choosing preferred installation type.
     */
    public function resolveConflict(string $moduleName, ModuleType $preferredType): ?ModuleInfo
    {
        if (!$this->hasModuleConflict($moduleName)) {
            return $this->getModuleInfo($moduleName);
        }

        $localModule = $this->localDetector->getModuleInfo($moduleName);
        $vendorModule = $this->vendorDetector->getModuleInfo($moduleName);

        if ($preferredType->equals(ModuleType::local()) && $localModule) {
            return $localModule;
        } elseif ($preferredType->equals(ModuleType::vendor()) && $vendorModule) {
            return $vendorModule;
        }

        // Fallback to existing conflict resolution
        return $this->createConflictModule($localModule, $vendorModule);
    }

    /**
     * Clear cached data from both detectors.
     */
    public function clearCache(): void
    {
        LocalModuleDetector::clearCache();
        VendorModuleDetector::clearCache();
    }
}
