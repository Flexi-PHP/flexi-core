<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Domain\ValueObjects\ConfigurationType;

/** Supplies a temporary configuration view with one candidate module included. */
final class ModuleCandidateConfigurationFilesProvider implements ConfigurationFilesProviderInterface
{
    private ConfigurationFilesProviderInterface $baseProvider;
    private array $configurationFiles;

    public function __construct(ConfigurationFilesProviderInterface $baseProvider, array $configurationFiles)
    {
        $this->baseProvider = $baseProvider;
        $this->configurationFiles = $configurationFiles;
    }

    public function getConfigurationFiles(ConfigurationType $configType, bool $includeCoreConfig = true): array
    {
        $files = $this->configurationFiles[$configType->value()] ?? [];
        if ($includeCoreConfig) {
            return $files;
        }

        $coreFile = realpath('./src/Config/' . $configType->value() . '.json');
        return array_values(array_filter($files, static function (string $file) use ($coreFile): bool {
            return $coreFile === false || realpath($file) !== $coreFile;
        }));
    }

    public function getAllConfigurationFiles(bool $includeCoreConfig = true): array
    {
        if ($includeCoreConfig) {
            return $this->configurationFiles;
        }

        $files = [];
        foreach (ConfigurationType::getAllTypes() as $type) {
            $configurationType = new ConfigurationType($type);
            $files[$type] = $this->getConfigurationFiles($configurationType, false);
        }

        return $files;
    }

    public function hasModuleConfiguration(string $moduleName, ConfigurationType $configType): bool
    {
        return $this->baseProvider->hasModuleConfiguration($moduleName, $configType);
    }

    public function getModuleConfigurationFile(string $moduleName, ConfigurationType $configType): ?string
    {
        return $this->baseProvider->getModuleConfigurationFile($moduleName, $configType);
    }
}
