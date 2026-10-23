<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Domain\Interfaces\ModuleCacheManagerInterface;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Contracts\Interfaces\LoggerInterface;

/**
 * Module detector for vendor-installed packages.
 *
 * Detects modules installed via Composer by scanning composer.json files
 * for Flexi module metadata in Composer's extra section. Uses intelligent caching based on
 * composer.lock modification time to improve performance.
 */
class VendorModuleDetector implements ModuleDetectorInterface
{
    private string $vendorPath;
    private ModuleCacheManagerInterface $cacheManager;
    private static ?array $installedModules = null;

    public function __construct(
        ModuleCacheManagerInterface $cacheManager,
        string $vendorPath = './vendor'
    ) {
        $this->vendorPath = rtrim($vendorPath, '/');
        $this->cacheManager = $cacheManager;
    }

    /**
     * Check if a module is installed in vendor directory.
     */
    public function isModuleInstalled(string $moduleName): bool
    {
        $installedModules = $this->getInstalledModules();
        $normalizedName = $this->normalizeModuleName($moduleName);
        return isset($installedModules[$normalizedName]);
    }

    /**
     * Get all modules installed in vendor directory.
     *
     * @return ModuleInfo[]
     */
    public function getAllModules(): array
    {
        $modules = [];
        $installedModules = $this->getInstalledModules();

        foreach ($installedModules as $moduleName => $moduleData) {
            $modules[$moduleName] = new ModuleInfo(
                $moduleData['name'],
                $moduleData['package'],
                ModuleType::vendor(),
                $moduleData['path'],
                $moduleData['version'],
                false, // Will be determined by ModuleStateManager
                $moduleData['metadata']
            );
        }

        return $modules;
    }

    /**
     * Get information about a specific vendor module.
     */
    public function getModuleInfo(string $moduleName): ?ModuleInfo
    {
        $installedModules = $this->getInstalledModules();
        $normalizedName = $this->normalizeModuleName($moduleName);

        if (!isset($installedModules[$normalizedName])) {
            return null;
        }

        $moduleData = $installedModules[$normalizedName];
        return new ModuleInfo(
            $moduleData['name'],
            $moduleData['package'],
            ModuleType::vendor(),
            $moduleData['path'],
            $moduleData['version'],
            false, // Will be determined by ModuleStateManager
            $moduleData['metadata']
        );
    }

    /**
     * Get statistics about vendor modules.
     */
    public function getModuleStatistics(): array
    {
        $installedModules = $this->getInstalledModules();

        $stats = [
            'total' => count($installedModules),
            'local' => 0,
            'vendor' => count($installedModules),
            'conflicts' => 0,
        ];

        // Analyze module types from metadata if available
        $typeCounts = [];
        foreach ($installedModules as $moduleData) {
            $type = $moduleData['metadata']['type'] ?? 'unknown';
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        $stats['by_type'] = $typeCounts;

        return $stats;
    }

    /**
     * Get all installed modules with their metadata.
     */
    private function getInstalledModules(): array
    {
        if (self::$installedModules !== null) {
            return self::$installedModules;
        }

        // Try to load from cache first
        $cachedModules = $this->loadFromCache();
        if ($cachedModules !== null) {
            self::$installedModules = $cachedModules;
            return self::$installedModules;
        }

        // Scan vendor directory and cache results
        $discoveredModules = $this->scanVendorForModules();
        $this->saveToCache($discoveredModules);

        self::$installedModules = $discoveredModules;
        return self::$installedModules;
    }

    /**
     * Load modules from cache if valid.
     */
    private function loadFromCache(): ?array
    {
        if (!$this->cacheManager->isCacheValid()) {
            return null;
        }

        $cachedModules = $this->cacheManager->getCachedModules('vendor');
        $indexedModules = [];

        foreach ($cachedModules as $module) {
            $indexedModules[$module->getName()] = [
                'name' => $module->getName(),
                'package' => $module->getPackage(),
                'path' => $module->getPath(),
                'version' => $module->getVersion(),
                'metadata' => $module->getMetadata()
            ];
        }

        // If no vendor modules found in cache, treat it as cache miss
        return empty($indexedModules) ? null : $indexedModules;
    }

    /**
     * Save discovered modules to cache.
     */
    private function saveToCache(array $discoveredModules): void
    {
        // Convert discovered vendor modules to ModuleInfo objects
        $moduleInfos = [];
        foreach ($discoveredModules as $moduleData) {
            $moduleInfos[] = new ModuleInfo(
                $moduleData['name'],
                $moduleData['package'],
                ModuleType::vendor(),
                $moduleData['path'],
                $moduleData['version'],
                false,
                $moduleData['metadata']
            );
        }

        $this->cacheManager->cacheModules($moduleInfos, 'vendor');
    }

    /**
     * Scan vendor directory for Flexi modules.
     */
    private function scanVendorForModules(): array
    {
        $modules = [];

        if (!is_dir($this->vendorPath)) {
            return $modules;
        }

        // Scan all vendor directories (vendor/organization/package)
        $vendors = $this->scanDirectory($this->vendorPath);

        foreach ($vendors as $vendor) {
            $vendorPath = $this->vendorPath . '/' . $vendor;
            if (!is_dir($vendorPath)) {
                continue;
            }

            $packages = $this->scanDirectory($vendorPath);

            foreach ($packages as $package) {
                $packagePath = $vendorPath . '/' . $package;
                $composerJsonPath = $packagePath . '/composer.json';

                if (!file_exists($composerJsonPath)) {
                    continue;
                }

                try {
                    $moduleData = $this->parseModuleComposerJson($composerJsonPath, $packagePath, $vendor . '/' . $package);
                    if ($moduleData) {
                        $modules[$moduleData['name']] = $moduleData;
                    }
                } catch (\JsonException $e) {
                    // Skip invalid composer.json files
                    continue;
                }
            }
        }

        return $modules;
    }

    /**
     * Scan a directory and return subdirectory names.
     */
    private function scanDirectory(string $directory): array
    {
        $items = [];

        try {
            $scanned = scandir($directory);
            if ($scanned === false) {
                return $items;
            }

            foreach ($scanned as $item) {
                if ($item === '.' || $item === '..' || !is_dir($directory . '/' . $item)) {
                    continue;
                }
                $items[] = $item;
            }
        } catch (\Exception $e) {
            // Handle cases where directory is not readable
            return [];
        }

        return $items;
    }

    /**
     * Parse composer.json to check if it's a Flexi module.
     */
    private function parseModuleComposerJson(string $composerJsonPath, string $packagePath, string $packageName): ?array
    {
        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['name'])) {
            return null;
        }

        // `extra.flexi` is the canonical format used by local and published modules.
        // Keep `extra.flexi-module` readable for packages created with the legacy format.
        $flexiModuleData = $data['extra']['flexi'] ?? $data['extra']['flexi-module'] ?? null;
        if (!is_array($flexiModuleData)) {
            return null;
        }

        // Extract module name from flexi-module metadata or derive from package name
        $moduleName = $flexiModuleData['module-name']
            ?? $flexiModuleData['name']
            ?? $this->deriveModuleNameFromPackage($packageName);

        return [
            'name' => $this->normalizeModuleName($moduleName),
            'package' => $data['name'],
            'path' => $packagePath,
            'version' => $data['version'] ?? 'unknown',
            'metadata' => [
                'description' => $data['description'] ?? '',
                'type' => $data['type'] ?? 'library',
                'license' => $data['license'] ?? '',
                'authors' => $data['authors'] ?? [],
                'keywords' => $data['keywords'] ?? [],
                'flexi' => $flexiModuleData,
                'dependencies' => isset($data['require']) ? count($data['require']) : 0,
                'composerExists' => true,
                'source' => 'vendor'
            ]
        ];
    }

    /**
     * Derive module name from package name.
     * Examples:
     *   - vendor/auth-module -> Auth
     *   - cubadevops/flexi-module-auth -> Auth
     *   - myvendor/user-management -> UserManagement
     */
    private function deriveModuleNameFromPackage(string $packageName): string
    {
        // Extract package name part (after slash)
        $parts = explode('/', $packageName);
        $packagePart = end($parts);

        // Remove common prefixes
        $name = preg_replace('/^(flexi-module-|module-|flexi-)/', '', $packagePart);

        // Convert kebab-case to PascalCase
        $words = explode('-', $name);
        $camelCase = array_map('ucfirst', $words);

        return implode('', $camelCase);
    }

    /**
     * Normalize module name for consistent comparison.
     */
    private function normalizeModuleName(string $moduleName): string
    {
        return ucfirst(strtolower($moduleName));
    }

    /**
     * Clear cached module data (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$installedModules = null;
    }

    /**
     * Force refresh of module discovery (invalidates cache).
     */
    public function refreshModules(): void
    {
        $this->cacheManager->invalidateCache();
        self::clearCache();
    }
}
