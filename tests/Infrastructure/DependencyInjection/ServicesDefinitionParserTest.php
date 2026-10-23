<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\DependencyInjection;

use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Test\TestData\TestDoubles\ServicesArrayCache;
use PHPUnit\Framework\TestCase;

final class ServicesDefinitionParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/services_parser_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testParseReadsDefinitionsAndCachesServices(): void
    {
        $cache = new ServicesArrayCache();
        $parser = new ServicesDefinitionParser($cache);

        $file = $this->tempDir . '/services.json';
        $definitions = [
            'services' => [
                [
                    'name' => 'database',
                    'class' => [
                        'name' => 'PDO',
                        'arguments' => ['mysql:host=localhost', 'user', 'pass'],
                    ],
                ],
                [
                    'name' => 'logger',
                    'alias' => 'monolog.logger',
                ],
                [
                    'name' => 'cache',
                    'factory' => ['class' => 'CacheFactory', 'method' => 'create'],
                ],
            ],
        ];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $services = $parser->parse($file);

        $this->assertCount(3, $services);
        $this->assertEquals([
            'name' => 'database',
            'class' => [
                'name' => 'PDO',
                'arguments' => ['mysql:host=localhost', 'user', 'pass'],
            ],
        ], $services['database']);
        $this->assertEquals('monolog.logger', $services['logger']);
        $this->assertEquals([
            'name' => 'cache',
            'factory' => ['class' => 'CacheFactory', 'method' => 'create'],
        ], $services['cache']);

        $cacheKey = 'services_file.' . md5($file);
        $this->assertArrayHasKey($cacheKey, $cache->store);
        $cachedServices = $cache->store[$cacheKey];
        $this->assertCount(3, $cachedServices);

        $processedFiles = $cache->store['service_definition_files'];
        $this->assertArrayHasKey($file, $processedFiles);
        $this->assertTrue($processedFiles[$file]);
    }

    public function testParseReturnsCachedServicesWhenFileAlreadyProcessed(): void
    {
        $file = $this->tempDir . '/cached_services.json';
        file_put_contents($file, json_encode(['services' => []], JSON_PRETTY_PRINT));

        $cache = new ServicesArrayCache([
            'service_definition_files' => [$file => true],
        ]);

        $cachedServices = [
            'cached_service' => ['name' => 'cached', 'class' => ['name' => 'CachedClass']]
        ];
        $cache->store['services_file.' . md5($file)] = $cachedServices;

        $parser = new ServicesDefinitionParser($cache);

        $result = $parser->parse($file);

        $this->assertSame($cachedServices, $result);
        $this->assertSame(0, $cache->setCalls['services_file.' . md5($file)] ?? 0);
    }

    public function testParseThrowsWhenFileDoesNotExist(): void
    {
        $cache = new ServicesArrayCache();
        $parser = new ServicesDefinitionParser($cache);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service file not found:');
        $parser->parse($this->tempDir . '/missing.json');
    }

    public function testParseHandlesEmptyServicesArray(): void
    {
        $cache = new ServicesArrayCache();
        $parser = new ServicesDefinitionParser($cache);

        $file = $this->tempDir . '/empty_services.json';
        $definitions = ['services' => []];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $services = $parser->parse($file);

        $this->assertSame([], $services);
    }

    public function testParseRejectsServiceWithoutRegistrationStrategy(): void
    {
        $cache = new ServicesArrayCache();
        $parser = new ServicesDefinitionParser($cache);

        $file = $this->tempDir . '/minimal_service.json';
        $definitions = [
            'services' => [
                [
                    'name' => 'minimal',
                ],
            ],
        ];
        file_put_contents($file, json_encode($definitions, JSON_PRETTY_PRINT));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration');
        $parser->parse($file);
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
