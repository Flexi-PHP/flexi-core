<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Classes\Traits\FileHandlerTrait;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Infrastructure\Http\Route;

class RoutesDefinitionParser
{
    use FileHandlerTrait;
    use JsonFileReader;

    private const ROUTE_DEFINITION_FILES_KEY = 'route_definition_files';
    private const ROUTES_CACHE_KEY_PREFIX = 'routes_file.';
    private const ERROR_FILE_NOT_FOUND = 'Routes file not found: %s';

    private CacheInterface $cache;
    private ConfigurationValidator $configurationValidator;
    private array $filesProcessed = [];

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
        $this->configurationValidator = new ConfigurationValidator();
        $this->filesProcessed = $cache->get(self::ROUTE_DEFINITION_FILES_KEY, []);
    }

    /**
     * Parses a routes definition file and returns the routes.
     *
     * @return Route[]
     */
    public function parse(string $filename): array
    {
        $filename = $this->normalize($filename);

        $this->ensureFileExists($filename);
        $this->configurationValidator->validateFile('routes', $filename);

        if ($this->isFileProcessed($filename)) {
            return $this->getCachedRoutes($filename);
        }

        $definitions = $this->readJsonFile($filename);
        $routes = $this->processDefinitions($definitions);

        $this->markFileAsProcessed($filename, $routes);

        return $routes;
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
     * Retrieves cached routes for a processed file.
     *
     * @return Route[]
     */
    private function getCachedRoutes(string $filename): array
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        return $this->cache->get($fileCacheKey, []);
    }

    /**
     * Processes the route definitions from the file.
     *
     * @return Route[]
     */
    private function processDefinitions(array $definitions): array
    {
        $routes = [];

        foreach ($definitions['routes'] as $defined_route) {
            $route = new Route(
                $defined_route['name'],
                $defined_route['path'],
                $defined_route['controller'],
                $defined_route['method'],
                $defined_route['parameters'] ?? [],
                $defined_route['middlewares'] ?? []
            );

            $routes[] = $route;
        }

        return $routes;
    }

    /**
     * Marks a file as processed and caches its routes.
     */
    private function markFileAsProcessed(string $filename, array $routes): void
    {
        $fileCacheKey = $this->getFileCacheKey($filename);

        // Temporarily mark the file as processed to avoid circular references
        $this->filesProcessed[$filename] = true;

        // Store the processed routes in cache
        $this->cache->set($fileCacheKey, $routes);

        // Update the cache for processed files
        $cachedFilesProcessed = $this->cache->get(self::ROUTE_DEFINITION_FILES_KEY, []);
        $cachedFilesProcessed[$filename] = true;
        $this->cache->set(self::ROUTE_DEFINITION_FILES_KEY, $cachedFilesProcessed);
    }

    /**
     * Generates a cache key for a given file.
     */
    private function getFileCacheKey(string $filename): string
    {
        return self::ROUTES_CACHE_KEY_PREFIX.md5($filename);
    }
}
