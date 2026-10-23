<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Composer\Semver\Semver;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\ValueObjects\ModuleInfo;

/** Validates active module requirements before the application runtime is built. */
class ModuleDependencyValidator
{
    private ModuleDetectorInterface $moduleDetector;
    private ModuleStateManagerInterface $moduleStateManager;
    private string $projectRoot;
    private string $coreVersion;

    public function __construct(
        ModuleDetectorInterface $moduleDetector,
        ModuleStateManagerInterface $moduleStateManager,
        string $projectRoot,
        string $coreVersion
    ) {
        $this->moduleDetector = $moduleDetector;
        $this->moduleStateManager = $moduleStateManager;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->coreVersion = $coreVersion;
    }

    /**
     * @throws \RuntimeException When active modules have incompatible or circular requirements.
     */
    public function validate(): void
    {
        $modules = $this->moduleDetector->getAllModules();
        $modulesByPackage = [];
        $activeModules = [];

        foreach ($modules as $module) {
            $modulesByPackage[strtolower($module->getPackage())] = $module;
            if ($this->moduleStateManager->isModuleActive($module->getName())) {
                $activeModules[$module->getName()] = $module;
            }
        }

        ksort($activeModules);
        $installedVersions = $this->getInstalledVersions();
        $dependencyGraph = [];

        foreach ($activeModules as $module) {
            $composerData = $this->readComposerMetadata($module);
            $this->validateCoreCompatibility($module, $composerData);
            $dependencyGraph[$module->getName()] = [];

            foreach (($composerData['require'] ?? []) as $package => $constraint) {
                $requiredModule = $modulesByPackage[strtolower($package)] ?? null;
                if (!$requiredModule instanceof ModuleInfo) {
                    continue;
                }

                if (!isset($activeModules[$requiredModule->getName()])) {
                    throw new \RuntimeException(sprintf(
                        'Active module "%s" requires module "%s" (%s), but that module is not active.',
                        $module->getName(),
                        $requiredModule->getName(),
                        $package
                    ));
                }

                $dependencyGraph[$module->getName()][] = $requiredModule->getName();
                $this->validateModuleVersion($module, $requiredModule, $package, $constraint, $installedVersions);
            }

            sort($dependencyGraph[$module->getName()]);
        }

        $this->assertAcyclic($dependencyGraph);
    }

    private function readComposerMetadata(ModuleInfo $module): array
    {
        $filename = $module->getPath() . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($filename)) {
            return [];
        }

        try {
            $metadata = json_decode((string) file_get_contents($filename), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to read module "%s" Composer metadata at "%s": %s',
                $module->getName(),
                $filename,
                $exception->getMessage()
            ), 0, $exception);
        }

        if (!is_array($metadata)) {
            throw new \RuntimeException(sprintf('Module "%s" has invalid Composer metadata at "%s".', $module->getName(), $filename));
        }

        return $metadata;
    }

    private function validateCoreCompatibility(ModuleInfo $module, array $composerData): void
    {
        $constraint = $composerData['extra']['flexi']['compatibility']['core'] ?? null;
        if ($constraint === null) {
            return;
        }
        if (!is_string($constraint) || '' === trim($constraint)) {
            throw new \RuntimeException(sprintf('Module "%s" declares an invalid core compatibility constraint.', $module->getName()));
        }

        try {
            $compatible = Semver::satisfies($this->coreVersion, $constraint);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Module "%s" declares invalid core compatibility constraint "%s": %s',
                $module->getName(),
                $constraint,
                $exception->getMessage()
            ), 0, $exception);
        }

        if (!$compatible) {
            throw new \RuntimeException(sprintf(
                'Module "%s" requires Flexi Core "%s", but the current core version is "%s".',
                $module->getName(),
                $constraint,
                $this->coreVersion
            ));
        }
    }

    private function validateModuleVersion(
        ModuleInfo $requiringModule,
        ModuleInfo $requiredModule,
        string $package,
        string $constraint,
        array $installedVersions
    ): void {
        if (!is_string($constraint) || '' === trim($constraint)) {
            throw new \RuntimeException(sprintf(
                'Module "%s" declares an invalid version constraint for module package "%s".',
                $requiringModule->getName(),
                $package
            ));
        }

        $version = $installedVersions[strtolower($package)] ?? $requiredModule->getVersion();
        if (!is_string($version) || '' === $version || 'unknown' === strtolower($version)) {
            throw new \RuntimeException(sprintf(
                'Cannot verify requirement "%s %s" declared by module "%s": the installed module version is unknown.',
                $package,
                $constraint,
                $requiringModule->getName()
            ));
        }

        try {
            $compatible = Semver::satisfies($version, $constraint);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Module "%s" declares invalid requirement "%s %s": %s',
                $requiringModule->getName(),
                $package,
                $constraint,
                $exception->getMessage()
            ), 0, $exception);
        }

        if (!$compatible) {
            throw new \RuntimeException(sprintf(
                'Module "%s" requires "%s %s", but installed module "%s" has version "%s".',
                $requiringModule->getName(),
                $package,
                $constraint,
                $requiredModule->getName(),
                $version
            ));
        }
    }

    private function getInstalledVersions(): array
    {
        $lockFile = $this->projectRoot . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($lockFile)) {
            return [];
        }

        try {
            $lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Unable to read Composer lock file "%s": %s', $lockFile, $exception->getMessage()), 0, $exception);
        }

        $versions = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
            if (isset($package['name'], $package['version'])) {
                $versions[strtolower($package['name'])] = $package['version'];
            }
        }

        return $versions;
    }

    private function assertAcyclic(array $graph): void
    {
        $states = [];
        $stack = [];
        $visit = function (string $moduleName) use (&$visit, &$states, &$stack, $graph): void {
            if (($states[$moduleName] ?? null) === 'visited') {
                return;
            }
            if (($states[$moduleName] ?? null) === 'visiting') {
                $cycleStart = array_search($moduleName, $stack, true);
                $cycle = false === $cycleStart ? $stack : array_slice($stack, $cycleStart);
                $cycle[] = $moduleName;
                throw new \RuntimeException('Circular module dependency detected: ' . implode(' -> ', $cycle) . '.');
            }

            $states[$moduleName] = 'visiting';
            $stack[] = $moduleName;
            foreach ($graph[$moduleName] ?? [] as $dependency) {
                $visit($dependency);
            }
            array_pop($stack);
            $states[$moduleName] = 'visited';
        };

        $moduleNames = array_keys($graph);
        sort($moduleNames);
        foreach ($moduleNames as $moduleName) {
            $visit($moduleName);
        }
    }
}
