<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Contracts\Classes\Traits\FileHandlerTrait;

/**
 * Provides configuration files from active modules and core
 */
class ConfigurationFilesProvider implements ConfigurationFilesProviderInterface
{

    use FileHandlerTrait;
    private const CORE_CONFIG_PATH = './src/Config';

    private ModuleStateManagerInterface $moduleStateManager;
    private HybridModuleDetector $moduleDetector;

    public function __construct(
        ModuleStateManagerInterface $moduleStateManager,
        HybridModuleDetector $moduleDetector
    ) {
        $this->moduleStateManager = $moduleStateManager;
        $this->moduleDetector = $moduleDetector;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfigurationFiles(ConfigurationType $configType, bool $includeCoreConfig = true): array
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
        $activeModules = $this->getActiveModules();
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
    private function getActiveModules(): array
    {
        $allModules = $this->moduleDetector->getAllModules();
        $activeModules = [];

        foreach ($allModules as $module) {
            if ($this->moduleStateManager->isModuleActive($module->getName())) {
                $activeModules[] = $module;
            }
        }

        return $activeModules;
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
