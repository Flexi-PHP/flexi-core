<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;

/** Validates the canonical Flexi Composer module manifest before activation. */
final class ModuleManifestValidator
{
    /** @return string[] */
    public function validate(ModuleInfo $module): array
    {
        $composerFile = rtrim($module->getPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($composerFile)) {
            return ['Module composer.json was not found at ' . $composerFile . '.'];
        }

        try {
            $composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return ['Module composer.json is invalid: ' . $exception->getMessage()];
        }

        if (!is_array($composer)) {
            return ['Module composer.json must contain a JSON object.'];
        }

        $errors = [];
        if (($composer['name'] ?? null) !== $module->getPackage()) {
            $errors[] = sprintf('Composer package name must match discovered package "%s".', $module->getPackage());
        }
        if (($composer['type'] ?? null) !== 'flexi-module') {
            $errors[] = 'Composer type must be "flexi-module".';
        }

        $metadata = $composer['extra']['flexi'] ?? null;
        if (!is_array($metadata)) {
            return array_merge($errors, ['Composer extra.flexi module metadata is required.']);
        }

        if (($metadata['schema-version'] ?? null) !== 1) {
            $errors[] = 'extra.flexi.schema-version must be 1.';
        }
        if (!is_string($metadata['module-name'] ?? null) || strcasecmp($metadata['module-name'], $module->getName()) !== 0) {
            $errors[] = sprintf('extra.flexi.module-name must match the discovered module name "%s".', $module->getName());
        }
        if (!isset($metadata['compatibility']['core']) || !is_string($metadata['compatibility']['core']) || trim($metadata['compatibility']['core']) === '') {
            $errors[] = 'extra.flexi.compatibility.core must contain a Composer version constraint.';
        }
        if (!isset($metadata['config-files']) || !is_array($metadata['config-files'])) {
            $errors[] = 'extra.flexi.config-files must be a list of relative paths.';
        } else {
            foreach ($metadata['config-files'] as $path) {
                if (!is_string($path) || $path === '' || $this->isUnsafePath($path)) {
                    $errors[] = 'extra.flexi.config-files may contain only safe relative paths.';
                    continue;
                }

                $resolved = realpath($module->getPath() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
                $moduleRoot = rtrim(realpath($module->getPath()) ?: '', DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if ($resolved === false || strpos($resolved, $moduleRoot) !== 0 || !is_file($resolved)) {
                    $errors[] = sprintf('Declared module configuration file "%s" is missing or outside the package.', $path);
                }
            }
        }
        if (!isset($metadata['provides']) || !is_array($metadata['provides'])) {
            $errors[] = 'extra.flexi.provides must be a list of capability identifiers.';
        } else {
            foreach ($metadata['provides'] as $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    $errors[] = 'extra.flexi.provides may contain only non-empty strings.';
                    break;
                }
            }
        }
        if (!isset($composer['require']['php']) || !is_string($composer['require']['php'])) {
            $errors[] = 'Composer require.php must declare the supported PHP range.';
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(ModuleInfo $module): void
    {
        $errors = $this->validate($module);
        if ($errors !== []) {
            throw new \RuntimeException(sprintf(
                'Module "%s" failed activation preflight: %s',
                $module->getName(),
                implode(' ', $errors)
            ));
        }
    }

    private function isUnsafePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        return $normalized[0] === '/'
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || in_array('..', explode('/', $normalized), true);
    }
}
