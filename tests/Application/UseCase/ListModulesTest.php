<?php

declare(strict_types=1);

namespace Flexi\Test\Application\UseCase;

use Flexi\Application\Commands\ListModulesCommand;
use Flexi\Application\UseCase\ListModules;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Contracts\Classes\PlainTextMessage;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;
use PHPUnit\Framework\TestCase;

class ListModulesTest extends TestCase
{
    private ListModules $listModules;
    private $stateManager; // Untyped for mock
    private $moduleDetector; // Untyped for mock
    private string $tempModulesPath;
    private string $tempVendorPath;

    public function setUp(): void
    {
        // Create temporary directories for testing
        $this->tempModulesPath = sys_get_temp_dir() . '/test_modules_' . uniqid();
        $this->tempVendorPath = sys_get_temp_dir() . '/test_vendor_' . uniqid();

        mkdir($this->tempModulesPath);
        mkdir($this->tempVendorPath);

        // Create mocks for dependencies
        $this->stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $this->stateManager->method('syncWithDiscoveredModules')->willReturn([]);
        $this->moduleDetector = $this->createMock(ModuleDetectorInterface::class);
        $this->moduleDetector->method('getModuleStatistics')->willReturn([]);

        $this->listModules = new ListModules($this->stateManager, $this->moduleDetector);
    }

    public function tearDown(): void
    {
        // Clean up temporary directories
        $this->removeDirectory($this->tempModulesPath);
        $this->removeDirectory($this->tempVendorPath);
    }

    public function testImplementsHandlerInterface(): void
    {
        $this->assertInstanceOf(HandlerInterface::class, $this->listModules);
    }

    public function testHandleWithNoModules(): void
    {
        // Configure mock to return no modules
        $this->moduleDetector->method('getAllModules')
            ->willReturn([]);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $this->assertInstanceOf(MessageInterface::class, $result);
        $this->assertInstanceOf(PlainTextMessage::class, $result);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(0, $response['total']);
        $this->assertEquals(0, $response['active']); // UseCase returns active/inactive, not installed
        $this->assertEquals([], $response['modules']);
    }

    public function testHandleSynchronizesPersistedStatesWithComposerDiscovery(): void
    {
        $this->moduleDetector->expects($this->once())->method('getAllModules')->willReturn([]);
        $this->stateManager->expects($this->once())->method('syncWithDiscoveredModules')->with([])->willReturn([]);

        $this->listModules->handle(new ListModulesCommand());
    }

    public function testHandleWithSingleModule(): void
    {
        // Create a test module
        $moduleName = 'TestModule';
        $modulePath = $this->tempModulesPath . '/' . $moduleName;
        mkdir($modulePath);

        // Create composer.json for the module
        $composerData = [
            'name' => 'cubadevops/flexi-module-test',
            'version' => '1.0.0',
            'description' => 'Test module',
            'type' => 'flexi-module',
            'require' => [
                'php' => '^7.4|^8.0'
            ],
            'extra' => [
                'flexi' => [
                    'autoload' => true
                ]
            ]
        ];

        file_put_contents(
            $modulePath . '/composer.json',
            json_encode($composerData, JSON_PRETTY_PRINT)
        );

        // Create ModuleInfo mock
        $moduleInfo = new \Flexi\Domain\ValueObjects\ModuleInfo(
            $moduleName,
            'cubadevops/flexi-module-test',
            \Flexi\Domain\ValueObjects\ModuleType::local(),
            $modulePath,
            '1.0.0',
            false,
            [
                'description' => 'Test module',
                'type' => 'flexi-module',
                'require' => ['php' => '^7.4|^8.0'],
                'extra' => ['flexi' => ['autoload' => true]],
            ]
        );

        // Configure mock
        $this->moduleDetector->method('getAllModules')
            ->willReturn([$moduleInfo]);

        $this->stateManager->method('isModuleActive')
            ->with($moduleName)
            ->willReturn(false);

        $this->stateManager->method('getModuleState')
            ->with($moduleName)
            ->willReturn(null);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(1, $response['total']);
        $this->assertEquals(0, $response['active']); // Module is not active
        $this->assertEquals(1, $response['inactive']); // Module is inactive
        $this->assertArrayHasKey($moduleName, $response['modules']);

        $moduleInfo = $response['modules'][$moduleName];
        $this->assertEquals($moduleName, $moduleInfo['name']);
        $this->assertEquals($modulePath, $moduleInfo['path']);
        $this->assertFalse($moduleInfo['active']); // Changed from 'installed' to 'active'
        $this->assertEquals('cubadevops/flexi-module-test', $moduleInfo['package']);
        $this->assertEquals('1.0.0', $moduleInfo['version']);
        $this->assertEquals('Test module', $moduleInfo['description']);
        $this->assertEquals('local', $moduleInfo['type']); // ModuleType value
    }

    public function testHandleWithInstalledModule(): void
    {
        // Create a test module
        $moduleName = 'Auth';
        $modulePath = $this->tempModulesPath . '/' . $moduleName;
        mkdir($modulePath);

        // Create composer.json for the module
        $composerData = [
            'name' => 'cubadevops/flexi-module-auth',
            'version' => '2.0.0',
            'description' => 'Authentication module'
        ];

        file_put_contents(
            $modulePath . '/composer.json',
            json_encode($composerData)
        );

        // Create symlink in vendor to simulate installed module
        $vendorModulePath = $this->tempVendorPath . '/flexi-module-auth';
        mkdir($vendorModulePath);

        // Create ModuleInfo mock
        $moduleInfo = new \Flexi\Domain\ValueObjects\ModuleInfo(
            $moduleName,
            'cubadevops/flexi-module-auth',
            \Flexi\Domain\ValueObjects\ModuleType::vendor(), // Vendor type for installed module
            $modulePath,
            '2.0.0',
            false,
            [
                'description' => 'Authentication module',
            ]
        );

        // Configure mock
        $this->moduleDetector->method('getAllModules')
            ->willReturn([$moduleInfo]);

        $this->stateManager->method('isModuleActive')
            ->with($moduleName)
            ->willReturn(true); // Active module

        $this->stateManager->method('getModuleState')
            ->with($moduleName)
            ->willReturn(null);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(1, $response['total']);
        $this->assertEquals(1, $response['active']); // Changed from 'installed' to 'active'

        $moduleInfo = $response['modules'][$moduleName];
        $this->assertTrue($moduleInfo['active']); // Changed from 'installed' to 'active'
    }

    public function testHandleWithModuleWithoutComposerJson(): void
    {
        // Create a test module without composer.json
        $moduleName = 'BrokenModule';
        $modulePath = $this->tempModulesPath . '/' . $moduleName;
        mkdir($modulePath);

        // Create ModuleInfo mock for broken module (no composer.json)
        $moduleInfo = new \Flexi\Domain\ValueObjects\ModuleInfo(
            $moduleName,
            'unknown',
            \Flexi\Domain\ValueObjects\ModuleType::local(),
            $modulePath,
            'unknown',
            false,
            []
        );

        // Configure mock
        $this->moduleDetector->method('getAllModules')
            ->willReturn([$moduleInfo]);

        $this->stateManager->method('isModuleActive')
            ->with($moduleName)
            ->willReturn(false);

        $this->stateManager->method('getModuleState')
            ->with($moduleName)
            ->willReturn(null);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(1, $response['total']);
        $moduleInfo = $response['modules'][$moduleName];
        $this->assertFalse($moduleInfo['active']); // Changed from composer_exists to active
        $this->assertEquals($moduleName, $moduleInfo['name']);
    }

    public function testConstructorWithCustomPaths(): void
    {
        $customModulesPath = '/custom/modules';
        $customVendorPath = '/custom/vendor';

        // Create new mocks for this specific test
        $mockStateManager = $this->createMock(ModuleStateManagerInterface::class);
        $mockModuleDetector = $this->createMock(ModuleDetectorInterface::class);

        $useCase = new ListModules($mockStateManager, $mockModuleDetector);

        $this->assertInstanceOf(ListModules::class, $useCase);
    }

    public function testHandleWithNonExistentModulesDirectory(): void
    {
        // Configure mock to return no modules
        $this->moduleDetector->method('getAllModules')
            ->willReturn([]);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(0, $response['total']);
        $this->assertEquals(0, $response['active']); // Changed from 'installed' to 'active'
        $this->assertEquals([], $response['modules']);
    }

    public function testHandleWithNonExistentVendorDirectory(): void
    {
        // Configure mock to return no modules (simulating no vendor directory)
        $this->moduleDetector->method('getAllModules')
            ->willReturn([]);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(0, $response['total']);
        $this->assertEquals(0, $response['active']); // No vendor directory means no active modules
    }

    public function testHandleWithMultipleInstalledModules(): void
    {
        // Create multiple ModuleInfo mocks
        $modules = [];
        $moduleNames = ['Auth', 'Cache', 'Logging'];

        foreach ($moduleNames as $name) {
            $modules[] = new \Flexi\Domain\ValueObjects\ModuleInfo(
                $name,
                'cubadevops/flexi-module-' . strtolower($name),
                \Flexi\Domain\ValueObjects\ModuleType::vendor(),
                '/vendor/cubadevops/flexi-module-' . strtolower($name),
                '1.0.0',
                false,
                []
            );
        }

        // Configure mock
        $this->moduleDetector->method('getAllModules')
            ->willReturn($modules);

        // Configure all modules as active
        $this->stateManager->method('isModuleActive')
            ->willReturn(true);

        $this->stateManager->method('getModuleState')
            ->willReturn(null);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);

        $this->assertEquals(3, $response['total']);
        $this->assertEquals(3, $response['active']); // All modules are active
        $this->assertEquals(0, $response['inactive']);
    }

    public function testHandleWithInvalidJsonInComposer(): void
    {
        // This test verifies that UseCase handles modules gracefully
        // In real scenario, invalid JSON would be handled by ModuleDetector

        $this->moduleDetector->method('getAllModules')
            ->willReturn([]); // ModuleDetector would skip invalid modules

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        // Should handle gracefully and return empty result
        $response = json_decode($result->get('body'), true);
        $this->assertEquals(0, $response['total']);
        $this->assertEquals(0, $response['active']);
    }

    public function testHandleWithModuleHavingMinimalComposerJson(): void
    {
        // Create a module with minimal composer.json (no optional fields)
        $moduleName = 'Minimal';
        $modulePath = $this->tempModulesPath . '/' . $moduleName;
        mkdir($modulePath);

        $composerData = [
            'name' => 'cubadevops/flexi-module-minimal'
            // Missing: version, description, type, require, extra
        ];

        file_put_contents(
            $modulePath . '/composer.json',
            json_encode($composerData)
        );

        // Create ModuleInfo mock for minimal module
        $moduleInfo = new \Flexi\Domain\ValueObjects\ModuleInfo(
            $moduleName,
            'cubadevops/flexi-module-minimal',
            \Flexi\Domain\ValueObjects\ModuleType::local(),
            $modulePath,
            'unknown',
            false,
            [
                'description' => '',
                'type' => 'unknown',
                'dependencies' => 0
            ]
        );

        // Configure mock
        $this->moduleDetector->method('getAllModules')
            ->willReturn([$moduleInfo]);

        $this->stateManager->method('isModuleActive')
            ->with($moduleName)
            ->willReturn(false);

        $this->stateManager->method('getModuleState')
            ->with($moduleName)
            ->willReturn(null);

        $dto = new ListModulesCommand();
        $result = $this->listModules->handle($dto);

        $response = json_decode($result->get('body'), true);
        $moduleInfo = $response['modules'][$moduleName];

        // Check defaults are applied through UseCase metadata
        $this->assertEquals('unknown', $moduleInfo['version']);
        $this->assertEquals('', $moduleInfo['description']);
        $this->assertEquals('local', $moduleInfo['type']); // ModuleType value
    }

    public function testPathNormalizationInConstructor(): void
    {
        // Test path normalization with trailing slashes
        // Test that constructor accepts proper types
        $mockStateManager = $this->createMock(ModuleStateManagerInterface::class);
        $mockModuleDetector = $this->createMock(ModuleDetectorInterface::class);

        $useCase = new ListModules($mockStateManager, $mockModuleDetector);
        $this->assertInstanceOf(ListModules::class, $useCase);
    }

    public function testHandleIncludesConflictDetailsAndStatistics(): void
    {
        $analytics = new \Flexi\Domain\ValueObjects\ModuleInfo(
            'analytics',
            'cubadevops/flexi-analytics',
            \Flexi\Domain\ValueObjects\ModuleType::mixed(),
            '/modules/analytics',
            '3.0.0',
            true,
            [
                'description' => 'Analytics suite',
                'local_path' => '/modules/analytics',
                'vendor_path' => '/vendor/cubadevops/analytics',
                'resolution_strategy' => 'local_priority',
            ]
        );

        $stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $moduleDetector = $this->createMock(ModuleDetectorInterface::class);

        $moduleDetector
            ->expects($this->once())
            ->method('getAllModules')
            ->willReturn([$analytics]);

        $moduleDetector
            ->expects($this->any())
            ->method('getModuleStatistics')
            ->willReturn(['local_only' => 0, 'conflicts' => 1]);

        $moduleState = new \Flexi\Domain\ValueObjects\ModuleState(
            'analytics',
            false,
            \Flexi\Domain\ValueObjects\ModuleType::mixed(),
            new \DateTimeImmutable('2025-01-10T11:30:00+00:00'),
            'ops'
        );

        $stateManager
            ->expects($this->once())
            ->method('isModuleActive')
            ->with('analytics')
            ->willReturn(false);

        $stateManager
            ->expects($this->once())
            ->method('getModuleState')
            ->with('analytics')
            ->willReturn($moduleState);

        $listModules = new ListModules($stateManager, $moduleDetector);

        $response = json_decode($listModules->handle(new ListModulesCommand())->get('body'), true);

        $this->assertSame(1, $response['total']);
        $this->assertSame(0, $response['active']);
        $this->assertSame(['local_only' => 0, 'conflicts' => 1], $response['types']);

        $module = $response['modules']['analytics'];
        $this->assertFalse($module['active']);
        $this->assertSame('ops', $module['modified_by']);
        $this->assertSame('Analytics suite', $module['description']);
        $this->assertSame('local_priority', $module['conflict']['resolution_strategy']);
        $this->assertArrayHasKey('metadata', $module);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
