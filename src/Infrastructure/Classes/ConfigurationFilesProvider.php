<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\Interfaces\CandidateConfigurationFilesProviderInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Contracts\Classes\Traits\FileHandlerTrait;

/**
 * Provides configuration files from active modules and core
 */
class ConfigurationFilesProvider implements CandidateConfigurationFilesProviderInterface
{

    use FileHandlerTrait;
    private const CORE_CONFIG_PATH = './src/Config';

    private ModuleStateManagerInterface $moduleStateManager;
    private HybridModuleDetector $moduleDetector;
    private string $projectRoot;
    private ?array $configuredModuleLoadOrder = null;

    public function __construct(
        ModuleStateManagerInterface $moduleStateManager,
        HybridModuleDetector $moduleDetector,
        string $projectRoot = './'
    ) {
        $this->moduleStateManager = $moduleStateManager;
        $this->moduleDetector = $moduleDetector;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigurationFiles(ConfigurationType $configType, bool $includeCoreConfig = true): array
    {
        return $this->getConfigurationFilesForModules($configType, $includeCoreConfig, null);
    }

    /**
     * Return configuration files as if a discovered module were active, without changing persisted state.
     *
     * @return array<string, string[]>
     */
    public function getAllConfigurationFilesIncluding(string $moduleName, bool $includeCoreConfig = true): array
    {
        $allFiles = [];
        foreach (ConfigurationType::getAllTypes() as $configType) {
            $type = new ConfigurationType($configType);
            $allFiles[$configType] = $this->getConfigurationFilesForModules($type, $includeCoreConfig, $moduleName);
        }

        return $allFiles;
    }

    /**
     * @return string[]
     */
    private function getConfigurationFilesForModules(
        ConfigurationType $configType,
        bool $includeCoreConfig,
        ?string $additionalActiveModule
    ): array
    {
        $files = [];

        // Add core configuration file if requested
        if ($includeCoreConfig) {
            $coreConfigFile = $this->getCoreConfigurationFile($configType);
            if ($coreConfigFile && file_exists($coreConfigFile)) {
                $files[] = $coreConfigFile;
            }
        }

        // Add active modules configuration files
        $activeModules = $this->getActiveModules($additionalActiveModule);
        foreach ($activeModules as $module) {
            $moduleConfigFile = $this->getModuleConfigurationFile($module->getName(), $configType);
            if ($moduleConfigFile && file_exists($moduleConfigFile)) {
                $files[] = $moduleConfigFile;
            }
        }

        return $files;
    }

    /**
     * {@inheritDoc}
     */
    public function getAllConfigurationFiles(bool $includeCoreConfig = true): array
    {
        $allFiles = [];

        foreach (ConfigurationType::getAllTypes() as $configType) {
            $configurationType = new ConfigurationType($configType);
            $allFiles[$configType] = $this->getConfigurationFiles($configurationType, $includeCoreConfig);
        }

        return $allFiles;
    }

    /**
     * {@inheritDoc}
     */
    public function hasModuleConfiguration(string $moduleName, ConfigurationType $configType): bool
    {
        $configFile = $this->getModuleConfigurationFile($moduleName, $configType);
        return $configFile && file_exists($configFile);
    }

    /**
     * {@inheritDoc}
     */
    public function getModuleConfigurationFile(string $moduleName, ConfigurationType $configType): ?string
    {
        try {
            $module = $this->findModuleByName($moduleName);
            if (!$module) {
                return null;
            }

            $modulePath = realpath($module->getPath());
            if (false === $modulePath) {
                return null;
            }

            foreach ($this->getModuleConfigurationPaths($module, $configType) as $relativePath) {
                $configFile = realpath($modulePath . DIRECTORY_SEPARATOR . $relativePath);
                if (false === $configFile || !$this->isPathWithinDirectory($configFile, $modulePath)) {
                    continue;
                }

                return $configFile;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a module configuration path from Composer metadata.
     * Older modules without `extra.flexi.config-files` use the historical Config/ convention.
     *
     * @return string[]
     */
    private function getModuleConfigurationPaths(ModuleInfo $module, ConfigurationType $configType): array
    {
        $flexiMetadata = $module->getMetadataValue('flexi', []);
        if (is_array($flexiMetadata) && array_key_exists('config-files', $flexiMetadata)) {
            $configFiles = $flexiMetadata['config-files'];
            if (!is_array($configFiles)) {
                return [];
            }

            $expectedFilename = $configType->value() . '.json';
            $matchingPaths = [];
            foreach ($configFiles as $configFile) {
                if (
                    !is_string($configFile)
                    || '' === $configFile
                    || $this->isAbsolutePath($configFile)
                    || in_array('..', explode('/', str_replace('\\', '/', $configFile)), true)
                ) {
                    continue;
                }

                if (basename(str_replace('\\', '/', $configFile)) === $expectedFilename) {
                    $matchingPaths[] = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configFile);
                }
            }

            return $matchingPaths;
        }

        return ["Config/{$configType->value()}.json"];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    private function isPathWithinDirectory(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return 0 === strpos($path, $directory);
    }

    /**
     * Get core configuration file path
     *
     * @param ConfigurationType $configType Configuration type
     * @return string|null Path to core config file or null if not found
     */
    private function getCoreConfigurationFile(ConfigurationType $configType): ?string
    {
        return $this->normalize(self::CORE_CONFIG_PATH . "/{$configType->value()}.json");
    }

    /**
     * Get all active modules
     *
     * @return ModuleInfo[] Array of active modules
     */
    private function getActiveModules(?string $additionalActiveModule = null): array
    {
        $allModules = $this->moduleDetector->getAllModules();
        $activeModules = [];

        foreach ($allModules as $module) {
            if (
                $this->moduleStateManager->isModuleActive($module->getName())
                || ($additionalActiveModule !== null && strcasecmp($module->getName(), $additionalActiveModule) === 0)
            ) {
                if ($module->hasConflict()) {
                    throw new \RuntimeException(sprintf(
                        'Active module "%s" has conflicting local and vendor sources. Resolve the duplicate before building configuration.',
                        $module->getName()
                    ));
                }
                $activeModules[] = $module;
            }
        }

        $configuredOrder = $this->getConfiguredModuleLoadOrder();
        $orderIndex = [];
        foreach ($configuredOrder as $index => $moduleName) {
            $orderIndex[strtolower($moduleName)] = $index;
        }

        usort($activeModules, static function (ModuleInfo $left, ModuleInfo $right) use ($orderIndex): int {
            $leftOrder = $orderIndex[strtolower($left->getName())] ?? null;
            $rightOrder = $orderIndex[strtolower($right->getName())] ?? null;
            if ($leftOrder !== null || $rightOrder !== null) {
                if ($leftOrder === null) {
                    return 1;
                }
                if ($rightOrder === null) {
                    return -1;
                }
                if ($leftOrder !== $rightOrder) {
                    return $leftOrder <=> $rightOrder;
                }
            }

            $nameComparison = strcasecmp($left->getName(), $right->getName());
            if (0 !== $nameComparison) {
                return $nameComparison;
            }

            $exactNameComparison = strcmp($left->getName(), $right->getName());
            if (0 !== $exactNameComparison) {
                return $exactNameComparison;
            }

            $packageComparison = strcmp($left->getPackage(), $right->getPackage());
            if (0 !== $packageComparison) {
                return $packageComparison;
            }

            return strcmp($left->getPath(), $right->getPath());
        });

        return $activeModules;
    }

    /**
     * Read the optional module order from the root Composer manifest.
     * Unknown and inactive names are permitted and naturally ignored during active-module sorting.
     *
     * @return string[]
     */
    private function getConfiguredModuleLoadOrder(): array
    {
        if ($this->configuredModuleLoadOrder !== null) {
            return $this->configuredModuleLoadOrder;
        }

        $composerFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            $this->configuredModuleLoadOrder = [];
            return $this->configuredModuleLoadOrder;
        }

        try {
            $composerData = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->configuredModuleLoadOrder = [];
            return $this->configuredModuleLoadOrder;
        }

        $extra = $composerData['extra'] ?? [];
        $flexiExtra = is_array($extra) ? ($extra['flexi'] ?? []) : [];
        if (!is_array($flexiExtra)) {
            throw new \RuntimeException(sprintf(
                'Root Composer manifest "%s" extra.flexi must be an object when module-load-order is configured.',
                $composerFile
            ));
        }
        if (!array_key_exists('module-load-order', $flexiExtra)) {
            $this->configuredModuleLoadOrder = [];
            return $this->configuredModuleLoadOrder;
        }

        $order = $flexiExtra['module-load-order'];
        if (!is_array($order) || array_values($order) !== $order) {
            throw new \RuntimeException(sprintf(
                'Root Composer manifest "%s" extra.flexi.module-load-order must be a sequential list of module names.',
                $composerFile
            ));
        }

        $seen = [];
        foreach ($order as $moduleName) {
            if (!is_string($moduleName) || '' === trim($moduleName)) {
                throw new \RuntimeException(sprintf(
                    'Root Composer manifest "%s" extra.flexi.module-load-order must contain non-empty module names.',
                    $composerFile
                ));
            }

            $normalizedName = strtolower(trim($moduleName));
            if (isset($seen[$normalizedName])) {
                throw new \RuntimeException(sprintf(
                    'Root Composer manifest "%s" extra.flexi.module-load-order contains duplicate module name "%s".',
                    $composerFile,
                    $moduleName
                ));
            }

            $seen[$normalizedName] = true;
        }

        $this->configuredModuleLoadOrder = array_map('trim', $order);

        return $this->configuredModuleLoadOrder;
    }

    /**
     * Find module by name
     *
     * @param string $moduleName Module name
     * @return ModuleInfo|null Module info or null if not found
     */
    private function findModuleByName(string $moduleName): ?ModuleInfo
    {
        $allModules = $this->moduleDetector->getAllModules();

        foreach ($allModules as $module) {
            if ($module->getName() === $moduleName) {
                return $module;
            }
        }

        return null;
    }
}
