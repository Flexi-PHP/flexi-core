<?php

declare(strict_types=1);

namespace Flexi\Application\UseCase;

use Flexi\Application\Services\ComposerProcessInterface;
use Flexi\Contracts\Classes\PlainTextMessage;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\ModuleManifestValidator;

/** Copies a vendor module into the application and switches Composer to its local path source. */
final class PublishModule implements HandlerInterface
{
    private ModuleDetectorInterface $moduleDetector;
    private ModuleStateManagerInterface $stateManager;
    private ComposerProcessInterface $composer;
    private string $projectRoot;

    public function __construct(
        ModuleDetectorInterface $moduleDetector,
        ModuleStateManagerInterface $stateManager,
        ComposerProcessInterface $composer,
        string $projectRoot = './'
    ) {
        $this->moduleDetector = $moduleDetector;
        $this->stateManager = $stateManager;
        $this->composer = $composer;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }

    public function handle(DTOInterface $dto): MessageInterface
    {
        $moduleName = trim((string) $dto->get('module_name'));
        try {
            $result = $this->publish($moduleName);
            return new PlainTextMessage(json_encode(['success' => true, 'module' => $moduleName, 'path' => $result], JSON_PRETTY_PRINT));
        } catch (\Throwable $exception) {
            return new PlainTextMessage(json_encode(['success' => false, 'module' => $moduleName, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT));
        }
    }

    private function publish(string $moduleName): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $moduleName)) {
            throw new \RuntimeException('Module name must contain only letters, digits, hyphens, and underscores.');
        }

        $module = $this->moduleDetector->getModuleInfo($moduleName);
        if ($module === null) {
            throw new \RuntimeException(sprintf('Module "%s" is not installed by Composer.', $moduleName));
        }
        $moduleName = $module->getName();
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $moduleName)) {
            throw new \RuntimeException('The module manifest contains a name that cannot be used as a local directory.');
        }
        if ($module->hasConflict() || !$module->getType()->equals(ModuleType::vendor())) {
            throw new \RuntimeException(sprintf('Module "%s" must have one vendor-only source before it can be published.', $moduleName));
        }

        (new ModuleManifestValidator())->assertValid($module);
        $root = realpath($this->projectRoot);
        if ($root === false) {
            throw new \RuntimeException('The project root could not be resolved.');
        }
        $composerFile = $root . DIRECTORY_SEPARATOR . 'composer.json';
        $lockFile = $root . DIRECTORY_SEPARATOR . 'composer.lock';
        if (!is_file($composerFile) || !is_file($lockFile)) {
            throw new \RuntimeException('A root composer.json and composer.lock are required to publish a vendor module.');
        }

        $rootData = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        $lockData = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
        $version = $this->findLockedVersion($lockData, $module->getPackage()) ?? $module->getVersion();
        if (!is_string($version) || trim($version) === '' || $version === 'unknown') {
            throw new \RuntimeException(sprintf('Unable to determine the installed Composer version for "%s" from composer.lock.', $module->getPackage()));
        }

        $modulesDirectory = $root . DIRECTORY_SEPARATOR . 'modules';
        if (!is_dir($modulesDirectory) && !mkdir($modulesDirectory, 0775, true) && !is_dir($modulesDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create local modules directory "%s".', $modulesDirectory));
        }
        $modulesRoot = realpath($modulesDirectory);
        if ($modulesRoot === false || strpos($modulesRoot . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new \RuntimeException('The local modules directory must resolve inside the project root.');
        }
        $destination = $modulesDirectory . DIRECTORY_SEPARATOR . $moduleName;
        if (file_exists($destination) || is_link($destination)) {
            throw new \RuntimeException(sprintf('Publication destination "%s" already exists.', $destination));
        }

        $composerBackup = (string) file_get_contents($composerFile);
        $lockBackup = (string) file_get_contents($lockFile);
        try {
            $this->copyPackage($module->getPath(), $destination);
        } catch (\Throwable $exception) {
            $this->removeDirectory($destination);
            throw $exception;
        }

        try {
            $repositories = $rootData['repositories'] ?? [];
            if (!is_array($repositories)) {
                throw new \RuntimeException('Root composer.json repositories must be a JSON array.');
            }
            $repository = [
                'type' => 'path',
                'url' => './modules/' . $moduleName,
                'options' => ['symlink' => true, 'versions' => [$module->getPackage() => $version]],
            ];
            $repositories = array_values(array_filter($repositories, static function ($entry) use ($moduleName): bool {
                return !is_array($entry) || ($entry['type'] ?? null) !== 'path' || ($entry['url'] ?? null) !== './modules/' . $moduleName;
            }));
            array_unshift($repositories, $repository);
            $rootData['repositories'] = $repositories;
            $this->writeJsonAtomically($composerFile, $rootData);

            $process = $this->composer->run([
                'update', $module->getPackage(), '--no-interaction', '--no-progress', '--no-scripts',
            ], $root);
            if ($process['exit_code'] !== 0) {
                throw new \RuntimeException('Composer update failed: ' . ($process['output'] ?: 'no diagnostic output'));
            }
        } catch (\Throwable $exception) {
            $this->restoreFile($composerFile, $composerBackup);
            $this->restoreFile($lockFile, $lockBackup);
            $this->removeDirectory($destination);
            try {
                $recovery = $this->composer->run(['install', '--no-interaction', '--no-progress', '--no-scripts'], $root);
                if ($recovery['exit_code'] !== 0) {
                    throw new \RuntimeException($recovery['output'] ?: 'no diagnostic output');
                }
            } catch (\Throwable $recoveryException) {
                throw new \RuntimeException($exception->getMessage() . ' Recovery composer install also failed: ' . $recoveryException->getMessage(), 0, $exception);
            }
            throw $exception;
        }

        if (method_exists($this->moduleDetector, 'clearCache')) {
            $this->moduleDetector->clearCache();
        }
        $this->stateManager->syncWithDiscoveredModules($this->moduleDetector->getAllModules());

        return 'modules/' . $moduleName;
    }

    private function findLockedVersion(array $lock, string $package): ?string
    {
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $lockedPackage) {
            if (($lockedPackage['name'] ?? null) === $package && is_string($lockedPackage['version'] ?? null)) {
                return ltrim($lockedPackage['version'], 'v');
            }
        }
        return null;
    }

    private function copyPackage(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new \RuntimeException('Cannot publish a package whose source directory is a symbolic link.');
        }
        if (!mkdir($destination, 0775, true)) {
            throw new \RuntimeException(sprintf('Unable to create publication directory "%s".', $destination));
        }
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }
            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $destination . DIRECTORY_SEPARATOR . $item;
            if (is_link($from)) {
                $this->removeDirectory($destination);
                throw new \RuntimeException(sprintf('Cannot publish package containing symbolic link "%s".', $item));
            }
            if (is_dir($from)) {
                $this->copyPackage($from, $to);
            } elseif (!copy($from, $to)) {
                $this->removeDirectory($destination);
                throw new \RuntimeException(sprintf('Unable to copy package file "%s".', $item));
            }
        }
    }

    private function writeJsonAtomically(string $file, array $data): void
    {
        $temporary = $file . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Unable to update "%s" atomically.', $file));
        }
    }

    private function restoreFile(string $file, string $contents): void
    {
        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to restore "%s" during publication rollback.', $file));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeDirectory($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);
    }
}
