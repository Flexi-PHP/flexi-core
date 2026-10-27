<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\DependencyInjection;

use Flexi\Infrastructure\DependencyInjection\ListenersDefinitionParser;
use Flexi\Contracts\Interfaces\CacheInterface;
use PHPUnit\Framework\TestCase;

final class ListenersDefinitionParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/listeners_parser_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testParseReadsDefinitionsAndCachesListeners(): void
    {
        $cache = new ListenersArrayCache();
        $parser = new ListenersDefinitionParser($cache);

        $file = $this->tempDir . '/listeners.json';
        $definitions = [
            [
                'event' => 'UserCreated',
                'listeners' => ['Modules\\User\\Listeners\\SendWelcomeEmail'],
            ],
            [
                'event' => 'OrderPlaced',
                'listeners' => ['Modules\\Order\\Listeners\\ProcessPayment'],
            ],
        ];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $listeners = $parser->parse($file);

        $this->assertCount(2, $listeners);
        $this->assertSame('UserCreated', $listeners[0]['event']);
        $this->assertSame('Modules\\User\\Listeners\\SendWelcomeEmail', $listeners[0]['handler']);
        $this->assertSame(0, $listeners[0]['priority']);
        $this->assertSame('OrderPlaced', $listeners[1]['event']);
        $this->assertSame('Modules\\Order\\Listeners\\ProcessPayment', $listeners[1]['handler']);
        $this->assertSame(0, $listeners[1]['priority']);

        $cacheKey = 'listeners_file.' . md5($file.hash_file('sha256', $file));
        $this->assertArrayHasKey($cacheKey, $cache->store);
        $cachedListeners = $cache->store[$cacheKey];
        $this->assertCount(2, $cachedListeners);

        $processedFiles = $cache->store['listeners_definition_files'];
        $this->assertArrayHasKey($file, $processedFiles);
        $this->assertTrue($processedFiles[$file]);
    }

    public function testParseReturnsCachedListenersWhenFileAlreadyProcessed(): void
    {
        $file = $this->tempDir . '/cached_listeners.json';
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));

        $cache = new ListenersArrayCache([
            'listeners_definition_files' => [$file => true],
        ]);

        $cachedListeners = [
            ['event' => 'CachedEvent', 'handler' => 'CachedHandler', 'priority' => 5]
        ];
        $cacheKey = 'listeners_file.' . md5($file.hash_file('sha256', $file));
        $cache->store[$cacheKey] = $cachedListeners;

        $parser = new ListenersDefinitionParser($cache);

        $result = $parser->parse($file);

        $this->assertSame($cachedListeners, $result);
        $this->assertSame(0, $cache->setCalls[$cacheKey] ?? 0);
    }

    public function testParseThrowsWhenFileDoesNotExist(): void
    {
        $cache = new ListenersArrayCache();
        $parser = new ListenersDefinitionParser($cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Listeners file not found:');
        $parser->parse($this->tempDir . '/missing.json');
    }

    public function testParseHandlesEmptyListenersArray(): void
    {
        $cache = new ListenersArrayCache();
        $parser = new ListenersDefinitionParser($cache);

        $file = $this->tempDir . '/empty_listeners.json';
        $definitions = [];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $listeners = $parser->parse($file);

        $this->assertSame([], $listeners);
    }

    public function testParseHandlesGroupedListeners(): void
    {
        $cache = new ListenersArrayCache();
        $parser = new ListenersDefinitionParser($cache);

        $file = $this->tempDir . '/no_listeners_key.json';
        $definitions = [['event' => 'example', 'listeners' => ['ExampleListener']]];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $listeners = $parser->parse($file);

        $this->assertSame([['event' => 'example', 'handler' => 'ExampleListener', 'priority' => 0]], $listeners);
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

final class ListenersArrayCache implements CacheInterface
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
