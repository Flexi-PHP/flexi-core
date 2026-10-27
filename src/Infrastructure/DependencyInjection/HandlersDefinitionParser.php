<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Classes\Traits\FileHandlerTrait;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Domain\ValueObjects\ConfigurationType;

class HandlersDefinitionParser
{
    use FileHandlerTrait;
    use JsonFileReader;

    private const HANDLERS_DEFINITION_FILES_KEY = 'handlers_definition_files';
    private const HANDLERS_CACHE_KEY_PREFIX = 'handlers_file.';
    private const ERROR_FILE_NOT_FOUND = 'Handlers file not found: %s';

    private CacheInterface $cache;
    private ConfigurationValidator $configurationValidator;
    private array $filesProcessed = [];

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
        $this->configurationValidator = new ConfigurationValidator();
        $this->filesProcessed = $cache->get(self::HANDLERS_DEFINITION_FILES_KEY, []);
    }

    /**
     * Parses a handlers definition file and returns the handlers.
     */
    public function parse(string $filename, ?ConfigurationType $configurationType = null): array
    {
        $filename = $this->normalize($filename);

        $this->ensureFileExists($filename);
        $type = $configurationType ? $configurationType->value() : basename($filename, '.json');
        $this->configurationValidator->validateFile($type, $filename);

        $fileCacheKey = $this->getFileCacheKey($filename);
        if ($this->isFileProcessed($filename) && $this->cache->has($fileCacheKey)) {
            return $this->cache->get($fileCacheKey, []);
        }

        $definitions = $this->readJsonFile($filename);
        $handlers = $this->processDefinitions($definitions);

        $this->markFileAsProcessed($filename, $handlers);

        return $handlers;
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
     * Processes the handler definitions from the file.
     */
    private function processDefinitions(array $definitions): array
    {
        $handlers = [];

        if (isset($definitions['handlers'])) {
            foreach ($definitions['handlers'] as $entry) {
                $handlers[] = [
                    'id' => $entry['id'],
                    'handler' => $entry['handler'],
                    'cli_alias' => $entry['cli_alias'] ?? null
                ];
            }
        }

        return $handlers;
    }

    /**
     * Marks a file as processed and caches its handlers.
     */
    private function markFileAsProcessed(string $filename, array $handlers): void
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        // Temporarily mark the file as processed to avoid circular references
        $this->filesProcessed[$filename] = true;

        // Store the processed handlers in cache
        $this->cache->set($fileCacheKey, $handlers);

        // Update the cache for processed files
        $cachedFilesProcessed = $this->cache->get(self::HANDLERS_DEFINITION_FILES_KEY, []);
        $cachedFilesProcessed[$filename] = true;
        $this->cache->set(self::HANDLERS_DEFINITION_FILES_KEY, $cachedFilesProcessed);
    }

    /**
     * Generates a cache key for a given file.
     */
    private function getFileCacheKey(string $filename): string
    {
        return self::HANDLERS_CACHE_KEY_PREFIX.md5($filename.hash_file('sha256', $filename));
    }
}
