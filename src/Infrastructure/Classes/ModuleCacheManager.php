<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\Interfaces\ModuleCacheManagerInterface;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;

/**
 * Manages module discovery cache based on Composer and module manifest content.
 *
 * This class provides intelligent caching of module discovery results
 * to avoid expensive filesystem scans on every request. Cache is invalidated when
 * composer.lock or any discovered module composer.json changes.
 */
class ModuleCacheManager implements ModuleCacheManagerInterface
{
    private string $cacheFilePath;
    private string $composerLockPath;

    public function __construct(string $cacheDir = './var', string $projectRoot = './')
    {
        $this->cacheFilePath = rtrim($cacheDir, '/') . '/modules-cache.json';
        $this->composerLockPath = rtrim($projectRoot, '/') . '/composer.lock';
    }

    /**
     * Get cached modules information for a specific type.
     *
     * @param string|null $type Module type ('local', 'vendor', or null for all)
     */
    public function getCachedModules(?string $type = null): array
    {
        if (!$this->cacheExists() || !$this->isCacheValid()) {
            return [];
        }

        try {
            $content = file_get_contents($this->cacheFilePath);
            if ($content === false) {
                return [];
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['modules_by_type']) || !is_array($data['modules_by_type'])) {
                return [];
            }

            $modules = [];

            // If type is specified, return only modules of that type
            if ($type !== null) {
                if (!isset($data['modules_by_type'][$type]) || !is_array($data['modules_by_type'][$type])) {
                    return [];
                }

                foreach ($data['modules_by_type'][$type] as $moduleData) {
                    $modules[] = $this->deserializeModuleInfo($moduleData);
                }
            } else {
                // Return all modules from all types
                foreach ($data['modules_by_type'] as $typeModules) {
                    if (!is_array($typeModules)) {
                        continue;
                    }
                    foreach ($typeModules as $moduleData) {
                        $modules[] = $this->deserializeModuleInfo($moduleData);
                    }
                }
            }

            return $modules;
        } catch (\JsonException | \Exception $e) {
            // If cache is corrupted, return empty array
            return [];
        }
    }

    /**
     * Cache modules information for a specific type.
     *
     * @param ModuleInfo[] $modules Array of modules to cache
     * @param string $type Module type ('local' or 'vendor')
     */
    public function cacheModules(array $modules, string $type): bool
    {
        try {
            // Ensure cache directory exists
            $cacheDir = dirname($this->cacheFilePath);
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true)) {
                return false;
            }

            // Load existing cache data to preserve other types
            $cacheData = $this->loadCacheData();

            // Update timestamp and composer.lock mtime
            $cacheData['timestamp'] = time();
            $cacheData['composer_lock_mtime'] = $this->getComposerLockModificationTime();

            // Ensure modules_by_type structure exists
            if (!isset($cacheData['modules_by_type']) || !is_array($cacheData['modules_by_type'])) {
                $cacheData['modules_by_type'] = [];
            }

            // Store modules for the specified type
            $cacheData['modules_by_type'][$type] = [];
            foreach ($modules as $module) {
                $cacheData['modules_by_type'][$type][] = $this->serializeModuleInfo($module);
            }
            $cacheData['composer_lock_hash'] = $this->getComposerLockHash();
            $cacheData['module_manifest_hashes'] = $this->getModuleManifestHashes($cacheData['modules_by_type']);
            $cacheData['module_root_hashes'] = $this->getModuleRootHashes($cacheData['modules_by_type']);

            $jsonData = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

            // Atomic write to prevent corruption
            $tempFile = $this->cacheFilePath . '.tmp';
            if (file_put_contents($tempFile, $jsonData, LOCK_EX) === false) {
                return false;
            }

            return rename($tempFile, $this->cacheFilePath);
        } catch (\JsonException | \Exception $e) {
            return false;
        }
    }

    /**
     * Check if cache is valid based on composer.lock modification time.
     */
    public function isCacheValid(): bool
    {
        if (!$this->cacheExists()) {
            return false;
        }

        try {
            $content = file_get_contents($this->cacheFilePath);
            if ($content === false) {
                return false;
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['composer_lock_hash']) || !isset($data['module_manifest_hashes']) || !isset($data['module_root_hashes'])) {
                return false;
            }

            return $data['composer_lock_hash'] === $this->getComposerLockHash()
                && $data['module_manifest_hashes'] === $this->getModuleManifestHashes($data['modules_by_type'] ?? [])
                && $data['module_root_hashes'] === $this->getModuleRootHashes($data['modules_by_type'] ?? []);
        } catch (\JsonException | \Exception $e) {
            return false;
        }
    }

    /**
     * Invalidate and clear the module cache.
     */
    public function invalidateCache(): bool
    {
        if (!$this->cacheExists()) {
            return true;
        }

        return unlink($this->cacheFilePath);
    }

    /**
     * Get the path to the cache file.
     */
    public function getCacheFilePath(): string
    {
        return $this->cacheFilePath;
    }

    /**
     * Check if cache file exists.
     */
    public function cacheExists(): bool
    {
        return file_exists($this->cacheFilePath);
    }

    /**
     * Load cache data from file.
     */
    private function loadCacheData(): array
    {
        if (!$this->cacheExists()) {
            return [
                'timestamp' => time(),
                'composer_lock_mtime' => $this->getComposerLockModificationTime(),
                'modules_by_type' => []
            ];
        }

        try {
            $content = file_get_contents($this->cacheFilePath);
            if ($content === false) {
                return [
                    'timestamp' => time(),
                    'composer_lock_mtime' => $this->getComposerLockModificationTime(),
                    'modules_by_type' => []
                ];
            }

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            // Ensure structure exists
            if (!isset($data['modules_by_type'])) {
                $data['modules_by_type'] = [];
            }

            return $data;
        } catch (\JsonException | \Exception $e) {
            return [
                'timestamp' => time(),
                'composer_lock_mtime' => $this->getComposerLockModificationTime(),
                'modules_by_type' => []
            ];
        }
    }

    /**
     * Get composer.lock modification time.
     */
    private function getComposerLockModificationTime(): ?int
    {
        if (!file_exists($this->composerLockPath)) {
            return null;
        }

        $mtime = filemtime($this->composerLockPath);
        return $mtime !== false ? $mtime : null;
    }

    private function getComposerLockHash(): ?string
    {
        if (!is_file($this->composerLockPath)) {
            return null;
        }

        $hash = hash_file('sha256', $this->composerLockPath);

        return $hash === false ? null : $hash;
    }

    private function getModuleManifestHashes(array $modulesByType): array
    {
        $hashes = [];
        foreach ($modulesByType as $modules) {
            if (!is_array($modules)) {
                continue;
            }
            foreach ($modules as $module) {
                if (!is_array($module) || !isset($module['path']) || !is_string($module['path'])) {
                    continue;
                }
                $manifestPath = rtrim($module['path'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'composer.json';
                $hash = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : null;
                $hashes[$manifestPath] = $hash === false ? null : $hash;
            }
        }

        ksort($hashes);

        return $hashes;
    }

    private function getModuleRootHashes(array $modulesByType): array
    {
        $roots = [];
        foreach ($modulesByType as $type => $modules) {
            if (!is_array($modules)) {
                continue;
            }
            foreach ($modules as $module) {
                if (!is_array($module) || !isset($module['path']) || !is_string($module['path'])) {
                    continue;
                }
                $path = rtrim($module['path'], DIRECTORY_SEPARATOR);
                $root = $type === 'vendor' ? dirname(dirname($path)) : dirname($path);
                $roots[$type][$root] = true;
            }
        }

        $hashes = [];
        foreach ($roots as $type => $typeRoots) {
            foreach (array_keys($typeRoots) as $root) {
                $pattern = $type === 'vendor'
                    ? $root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'
                    : $root.DIRECTORY_SEPARATOR.'*';
                $directories = array_filter(glob($pattern) ?: [], 'is_dir');
                sort($directories);
                $manifestHashes = [];
                foreach ($directories as $directory) {
                    $manifest = $directory.DIRECTORY_SEPARATOR.'composer.json';
                    $hash = is_file($manifest) ? hash_file('sha256', $manifest) : null;
                    $manifestHashes[$directory] = $hash === false ? null : $hash;
                }
                $hashes[$type][$root] = hash('sha256', json_encode($manifestHashes));
            }
        }

        ksort($hashes);
        foreach ($hashes as &$typeHashes) {
            ksort($typeHashes);
        }
        unset($typeHashes);

        return $hashes;
    }

    /**
     * Serialize ModuleInfo for caching.
     */
    private function serializeModuleInfo(ModuleInfo $module): array
    {
        return [
            'name' => $module->getName(),
            'package' => $module->getPackage(),
            'type' => $module->getType()->getValue(),
            'path' => $module->getPath(),
            'version' => $module->getVersion(),
            'active' => $module->isActive(),
            'metadata' => $module->getMetadata()
        ];
    }

    /**
     * Deserialize cached data to ModuleInfo.
     */
    private function deserializeModuleInfo(array $data): ModuleInfo
    {
        $typeValue = $data['type'] ?? 'local';

        if ($typeValue === 'vendor') {
            $type = ModuleType::vendor();
        } elseif ($typeValue === 'mixed') {
            $type = ModuleType::mixed();
        } else {
            $type = ModuleType::local();
        }

        return new ModuleInfo(
            $data['name'] ?? '',
            $data['package'] ?? '',
            $type,
            $data['path'] ?? '',
            $data['version'] ?? 'unknown',
            $data['active'] ?? false, // isActive will be determined by ModuleStateManager
            $data['metadata'] ?? []
        );
    }
}
