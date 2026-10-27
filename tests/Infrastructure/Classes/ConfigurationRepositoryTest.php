<?php

declare(strict_types=1);

namespace Flexi\Tests\Infrastructure\Classes;

use Flexi\Infrastructure\Classes\ConfigurationRepository;
use PHPUnit\Framework\TestCase;

class ConfigurationRepositoryTest extends TestCase
{
    private const CUSTOM_KEY = 'FLEXI_TEST_CUSTOM_KEY';

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV[self::CUSTOM_KEY] = 'custom-value';
        putenv(self::CUSTOM_KEY . '=custom-value');
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::CUSTOM_KEY]);
        putenv(self::CUSTOM_KEY);
        parent::tearDown();
    }

    public function testRepositoryLoadsEnvironmentVariables(): void
    {
        $repository = new ConfigurationRepository();

        $this->assertTrue($repository->has('ROOT_DIR'));
        $this->assertTrue($repository->has(self::CUSTOM_KEY));
        $this->assertSame('custom-value', $repository->get(self::CUSTOM_KEY));

        $rootDir = realpath(dirname(__DIR__, 3));
        $this->assertSame($rootDir, $repository->get('ROOT_DIR'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $repository = new ConfigurationRepository();

        $this->assertFalse($repository->has('UNKNOWN_KEY_' . uniqid()));
    }

    public function testGetAllReturnsArrayWithExpectedKeys(): void
    {
        $repository = new ConfigurationRepository();

        $all = $repository->getAll();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('ROOT_DIR', $all);
        $this->assertArrayHasKey('APP_DIR', $all);
        $this->assertArrayHasKey('MODULES_DIR', $all);
        $this->assertArrayHasKey(self::CUSTOM_KEY, $all);
    }
}
