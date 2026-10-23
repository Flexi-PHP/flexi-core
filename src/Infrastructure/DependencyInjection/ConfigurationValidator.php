<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Interfaces\ConfigurationInterface;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;

/** Validates Flexi configuration documents against schemas shipped by flexi/contracts. */
class ConfigurationValidator
{
    private const SCHEMA_FILES = [
        'services' => 'services.schema.json',
        'routes' => 'routes.schema.json',
        'listeners' => 'listeners.schema.json',
        'commands' => 'commands.schema.json',
        'queries' => 'queries.schema.json',
    ];

    private CompliantValidator $validator;
    private ErrorFormatter $errorFormatter;
    private string $schemaDirectory;

    public function __construct(?string $schemaDirectory = null)
    {
        $this->validator = new CompliantValidator();
        $this->validator->setMaxErrors(20);
        $this->validator->setStopAtFirstError(false);
        $this->errorFormatter = new ErrorFormatter();
        $contractFile = (new \ReflectionClass(ConfigurationInterface::class))->getFileName();
        $contractDirectory = dirname($contractFile, 3);
        $this->schemaDirectory = $schemaDirectory ?? $contractDirectory . '/schemas/configuration/v1';
    }

    /**
     * Validate a configuration file before a parser registers its definitions.
     *
     * @throws \RuntimeException When JSON is malformed or does not match its schema.
     */
    public function validateFile(string $type, string $filename): void
    {
        if (!isset(self::SCHEMA_FILES[$type])) {
            throw new \InvalidArgumentException(sprintf('Unsupported Flexi configuration type "%s".', $type));
        }

        $content = file_get_contents($filename);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Unable to read configuration file "%s".', $filename));
        }

        try {
            $document = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON in module "%s", file "%s": %s',
                $this->getModuleName($filename),
                $filename,
                $exception->getMessage()
            ), 0, $exception);
        }

        $schemaFile = $this->schemaDirectory . DIRECTORY_SEPARATOR . self::SCHEMA_FILES[$type];
        $schemaContent = file_get_contents($schemaFile);
        if (false === $schemaContent) {
            throw new \RuntimeException(sprintf('Flexi configuration schema is missing: "%s".', $schemaFile));
        }

        try {
            $schema = json_decode($schemaContent, false, 512, JSON_THROW_ON_ERROR);
            $result = $this->validator->validate($document, $schema);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to validate configuration for module "%s", file "%s": %s',
                $this->getModuleName($filename),
                $filename,
                $exception->getMessage()
            ), 0, $exception);
        }

        if ($result->isValid()) {
            return;
        }

        $errors = $this->errorFormatter->formatKeyed($result->error());
        $details = [];
        foreach ($errors as $path => $messages) {
            $messages = is_array($messages) ? $messages : [$messages];
            $details[] = sprintf('%s: %s', $path, implode('; ', array_map('strval', $messages)));
        }

        throw new \RuntimeException(sprintf(
            'Invalid configuration for module "%s", file "%s": %s',
            $this->getModuleName($filename),
            $filename,
            implode(' | ', $details)
        ));
    }

    private function getModuleName(string $filename): string
    {
        $directory = dirname(realpath($filename) ?: $filename);
        while (true) {
            $composerFile = $directory . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($composerFile)) {
                $composerData = json_decode((string) file_get_contents($composerFile), true);
                $moduleName = $composerData['extra']['flexi']['module-name'] ?? null;
                if (is_string($moduleName) && '' !== $moduleName) {
                    return $moduleName;
                }
                if (($composerData['name'] ?? null) === 'flexi/core') {
                    return 'core';
                }
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return 'unknown';
    }
}
