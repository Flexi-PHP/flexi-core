<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Classes\Traits\FileHandlerTrait;
use Flexi\Contracts\Classes\Traits\GlobFileReader;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\CacheInterface;

class ServicesDefinitionParser
{
    use FileHandlerTrait;
    use JsonFileReader;
    use GlobFileReader;

    private const SERVICE_DEFINITION_FILES_KEY = 'service_definition_files';
    private const SERVICES_CACHE_KEY_PREFIX = 'services_file.';
    private const ERROR_SERVICE_ALREADY_DEFINED = 'Service %s is already defined in %s';
    private const ERROR_FILE_NOT_FOUND = 'Service file not found: %s';

    private CacheInterface $cache;
    private ConfigurationValidator $configurationValidator;
    private array $serviceDefinitions = [];
    private array $filesProcessed = [];

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
        $this->configurationValidator = new ConfigurationValidator();
        $this->filesProcessed = $cache->get(self::SERVICE_DEFINITION_FILES_KEY, []);
    }

    /**
     * Parses a service definition file and returns the services.
     */
    public function parse(string $filename): array
    {
        $filename = $this->normalize($filename);

        $this->ensureFileExists($filename);
        $this->configurationValidator->validateFile('services', $filename);

        if ($this->isFileProcessed($filename)) {
            return $this->getCachedServices($filename);
        }

        $definitions = $this->readJsonFile($filename);
        $services = $this->processDefinitions($definitions, $filename);

        $this->markFileAsProcessed($filename, $services);

        return $services;
    }

    /**
     * Ensures the file exists, otherwise throws an exception.
     *
     * @throws \RuntimeException
     */
    private function ensureFileExists(string $filename): void
    {
        if (!$this->fileExists($filename)) {
            throw new \RuntimeException(sprintf(self::ERROR_FILE_NOT_FOUND, $filename));
        }
    }

    /**
     * Checks if a file has already been processed.
     */
    private function isFileProcessed(string $filename): bool
    {
        return isset($this->filesProcessed[$filename]);
    }

    /**
     * Retrieves cached services for a processed file.
     */
    private function getCachedServices(string $filename): array
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        return $this->cache->get($fileCacheKey, []);
    }

    /**
     * Processes the service definitions from the file.
     */
    private function processDefinitions(array $definitions, string $filename): array
    {
        $services = [];

        foreach ($definitions['services'] as $service) {
            if (array_key_exists($service['name'], $services)) {
                throw new \RuntimeException(sprintf(
                    'Service ID "%s" is defined more than once in %s.',
                    $service['name'],
                    $filename
                ));
            }
            if (isset($service['alias'])) {
                $services[$service['name']] = $service['alias'];
            } elseif (isset($service['factory']) || isset($service['class'])) {
                $services[$service['name']] = $service;
            }
        }

        return $services;
    }

    /**
     * Marks a file as processed and caches its services.
     */
    private function markFileAsProcessed(string $filename, array $services): void
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        // Temporarily mark the file as processed to avoid circular references
        $this->filesProcessed[$filename] = true;

        // Store the processed services in cache
        $this->cache->set($fileCacheKey, $services);

        // Update the cache for processed files
        $cachedFilesProcessed = $this->cache->get(self::SERVICE_DEFINITION_FILES_KEY, []);
        $cachedFilesProcessed[$filename] = true;
        $this->cache->set(self::SERVICE_DEFINITION_FILES_KEY, $cachedFilesProcessed);
    }

    /**
     * Generates a cache key for a given file.
     */
    private function getFileCacheKey(string $filename): string
    {
        return self::SERVICES_CACHE_KEY_PREFIX.md5($filename);
    }
}
