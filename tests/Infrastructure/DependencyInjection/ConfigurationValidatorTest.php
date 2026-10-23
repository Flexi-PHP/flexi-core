<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\DependencyInjection;

use Flexi\Infrastructure\DependencyInjection\ConfigurationValidator;
use PHPUnit\Framework\TestCase;

final class ConfigurationValidatorTest extends TestCase
{
    private string $tempDir;
    private ConfigurationValidator $validator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/configuration_validator_' . uniqid();
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'flexi/module-auth',
            'extra' => ['flexi' => ['module-name' => 'Auth']],
        ]));
        $this->validator = new ConfigurationValidator();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    public function testValidatesGroupedModuleListeners(): void
    {
        $file = $this->writeConfiguration('listeners.json', json_encode([
            ['event' => 'user.created', 'listeners' => ['Auth\\WelcomeListener']],
        ]));

        $this->assertNull($this->validator->validateFile('listeners', $file));
    }

    public function testValidatesCommandAndQueryDefinitions(): void
    {
        foreach (['commands', 'queries'] as $type) {
            $file = $this->writeConfiguration($type . '.json', json_encode([
                'handlers' => [[
                    'id' => 'Flexi\\Example\\Example' . ucfirst($type),
                    'handler' => 'Flexi\\Example\\Handle' . ucfirst($type),
                    'cli_alias' => strtolower($type) . ':example',
                ]],
            ]));

            $this->assertNull($this->validator->validateFile($type, $file));
        }
    }

    public function testReportsModuleFileAndInvalidField(): void
    {
        $file = $this->writeConfiguration('routes.json', json_encode([
            'routes' => [[
                'name' => 'health',
                'path' => 'health',
                'method' => 'FETCH',
                'controller' => 'HealthController',
            ]],
        ]));

        try {
            $this->validator->validateFile('routes', $file);
            $this->fail('Expected schema validation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('module "Auth"', $exception->getMessage());
            $this->assertStringContainsString($file, $exception->getMessage());
            $this->assertStringContainsString('/routes/0/path', $exception->getMessage());
            $this->assertStringContainsString('/routes/0/method', $exception->getMessage());
        }
    }

    public function testReportsMalformedJsonWithModuleAndFile(): void
    {
        $file = $this->writeConfiguration('services.json', '{"services": [}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON in module "Auth"');
        $this->validator->validateFile('services', $file);
    }

    private function writeConfiguration(string $filename, string $contents): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $contents);

        return $path;
    }
}
