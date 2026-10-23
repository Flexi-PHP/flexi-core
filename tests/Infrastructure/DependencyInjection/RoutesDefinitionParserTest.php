<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\DependencyInjection;

use Flexi\Infrastructure\DependencyInjection\RoutesDefinitionParser;
use Flexi\Infrastructure\Http\Route;
use Flexi\Contracts\Interfaces\CacheInterface;
use PHPUnit\Framework\TestCase;

final class RoutesDefinitionParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/routes_parser_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testParseReadsDefinitionsAndCachesRoutes(): void
    {
        $cache = new ArrayCache();
        $parser = new RoutesDefinitionParser($cache);

        $file = $this->tempDir . '/routes.json';
        $definitions = [
            'routes' => [
                [
                    'name' => 'dashboard',
                    'path' => '/dashboard',
                    'controller' => 'Modules\\Dashboard\\Controllers\\ShowDashboard',
                    'method' => 'GET',
                    'parameters' => [
                        ['name' => 'tenant', 'required' => true],
                    ],
                    'middlewares' => ['auth'],
                ],
                [
                    'name' => 'health',
                    'path' => '/health',
                    'controller' => 'Modules\\Core\\Controllers\\Healthcheck',
                    'method' => 'GET',
                ],
            ],
        ];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $routes = $parser->parse($file);

        $this->assertCount(2, $routes);
        $this->assertContainsOnlyInstancesOf(Route::class, $routes);
        $this->assertSame('/dashboard', $routes[0]->getPath());
        $this->assertSame(['auth'], $routes[0]->getMiddlewares());

        $cacheKey = 'routes_file.' . md5($file.hash_file('sha256', $file));
        $this->assertArrayHasKey($cacheKey, $cache->store);
        $cachedRoutes = $cache->store[$cacheKey];
        $this->assertCount(2, $cachedRoutes);
        $this->assertContainsOnlyInstancesOf(Route::class, $cachedRoutes);

        $processedFiles = $cache->store['route_definition_files'];
        $this->assertArrayHasKey($file, $processedFiles);
        $this->assertTrue($processedFiles[$file]);
    }

    public function testParseReturnsCachedRoutesWhenFileAlreadyProcessed(): void
    {
        $file = $this->tempDir . '/cached_routes.json';
        file_put_contents($file, json_encode(['routes' => []], JSON_PRETTY_PRINT));

        $cache = new ArrayCache([
            'route_definition_files' => [$file => true],
        ]);

        $cachedRoutes = [new Route('cached', '/cached', 'Handler', 'GET')];
        $cacheKey = 'routes_file.' . md5($file.hash_file('sha256', $file));
        $cache->store[$cacheKey] = $cachedRoutes;

        $parser = new RoutesDefinitionParser($cache);

        $result = $parser->parse($file);

        $this->assertSame($cachedRoutes, $result);
        $this->assertSame(0, $cache->setCalls[$cacheKey] ?? 0);
    }

    public function testParseThrowsWhenFileDoesNotExist(): void
    {
        $cache = new ArrayCache();
        $parser = new RoutesDefinitionParser($cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Routes file not found:');
        $parser->parse($this->tempDir . '/missing.json');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}

final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    /** @var array<string, int> */
    public array $setCalls = [];

    public function __construct(array $initial = [])
    {
        $this->store = $initial;
    }

    public function get($key, $default = null)
    {
        return $this->store[$key] ?? $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->store[$key] = $value;
        $this->setCalls[$key] = ($this->setCalls[$key] ?? 0) + 1;
        return true;
    }

    public function delete($key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function getMultiple($keys, $default = null)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has($key): bool
    {
        return array_key_exists($key, $this->store);
    }
}
