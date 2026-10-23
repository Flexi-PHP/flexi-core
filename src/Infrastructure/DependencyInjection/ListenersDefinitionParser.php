<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Classes\Traits\FileHandlerTrait;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\CacheInterface;

class ListenersDefinitionParser
{
    use FileHandlerTrait;
    use JsonFileReader;

    private const LISTENERS_DEFINITION_FILES_KEY = 'listeners_definition_files';
    private const LISTENERS_CACHE_KEY_PREFIX = 'listeners_file.';
    private const ERROR_FILE_NOT_FOUND = 'Listeners file not found: %s';

    private CacheInterface $cache;
    private ConfigurationValidator $configurationValidator;
    private array $filesProcessed = [];

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
        $this->configurationValidator = new ConfigurationValidator();
        $this->filesProcessed = $cache->get(self::LISTENERS_DEFINITION_FILES_KEY, []);
    }

    /**
     * Parses a listeners definition file and returns the listeners.
     */
    public function parse(string $filename): array
    {
        $filename = $this->normalize($filename);

        $this->ensureFileExists($filename);
        $this->configurationValidator->validateFile('listeners', $filename);

        $fileCacheKey = $this->getFileCacheKey($filename);
        if ($this->isFileProcessed($filename) && $this->cache->has($fileCacheKey)) {
            return $this->cache->get($fileCacheKey, []);
        }

        $definitions = $this->readJsonFile($filename);
        $listeners = $this->processDefinitions($definitions);

        $this->markFileAsProcessed($filename, $listeners);

        return $listeners;
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
     * Processes the listener definitions from the file.
     */
    private function processDefinitions(array $definitions): array
    {
        $listeners = [];

        foreach ($definitions as $eventDefinition) {
            foreach ($eventDefinition['listeners'] as $handler) {
                $listeners[] = [
                    'event' => $eventDefinition['event'],
                    'handler' => $handler,
                    'priority' => 0
                ];
            }
        }

        return $listeners;
    }

    /**
     * Marks a file as processed and caches its listeners.
     */
    private function markFileAsProcessed(string $filename, array $listeners): void
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        // Temporarily mark the file as processed to avoid circular references
        $this->filesProcessed[$filename] = true;

        // Store the processed listeners in cache
        $this->cache->set($fileCacheKey, $listeners);

        // Update the cache for processed files
        $cachedFilesProcessed = $this->cache->get(self::LISTENERS_DEFINITION_FILES_KEY, []);
        $cachedFilesProcessed[$filename] = true;
        $this->cache->set(self::LISTENERS_DEFINITION_FILES_KEY, $cachedFilesProcessed);
    }

    /**
     * Generates a cache key for a given file.
     */
    private function getFileCacheKey(string $filename): string
    {
        return self::LISTENERS_CACHE_KEY_PREFIX.md5($filename.hash_file('sha256', $filename));
    }
}
