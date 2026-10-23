<?php

declare(strict_types=1);

namespace Flexi\Tests\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\ConfigurationFilesProvider;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigurationFilesProviderTest extends TestCase
{
    private string $temporaryModulesPath;

    /** @var MockObject&ModuleStateManagerInterface */
    private ModuleStateManagerInterface $stateManager;

    /** @var MockObject&HybridModuleDetector */
    private HybridModuleDetector $moduleDetector;

    private ConfigurationFilesProvider $provider;

    protected function setUp(): void
    {
        $this->temporaryModulesPath = sys_get_temp_dir() . '/flexi-config-provider-' . uniqid('', true);
        $this->createDirectory($this->temporaryModulesPath);

        $this->stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $this->moduleDetector = $this->createMock(HybridModuleDetector::class);

        $this->provider = new ConfigurationFilesProvider($this->stateManager, $this->moduleDetector);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryModulesPath ?? '');
        parent::tearDown();
    }

    public function testGetConfigurationFilesReturnsCoreAndActiveModules(): void
    {
        $activeModule = $this->createModule('Blog', ['routes' => '{"route": "blog"}']);
        $inactiveModule = $this->createModule('Shop', ['routes' => '{"route": "shop"}']);

        $this->moduleDetector
            ->method('getAllModules')
            ->willReturn([$activeModule, $inactiveModule]);

        $this->stateManager
            ->method('isModuleActive')
            ->willReturnMap([
                ['Blog', true],
                ['Shop', false],
            ]);

        $files = $this->provider->getConfigurationFiles(ConfigurationType::routes());

        $coreRoutesPath = realpath(dirname(__DIR__, 3) . '/src/Config/routes.json');
        $moduleRoutesPath = realpath($activeModule->getPath() . '/Config/routes.json');

        $this->assertSame([$coreRoutesPath, $moduleRoutesPath], $files);
    }

    public function testGetConfigurationFilesCanExcludeCoreConfiguration(): void
    {
        $module = $this->createModule('Blog', ['routes' => '{"route": "blog"}']);

        $this->moduleDetector
            ->method('getAllModules')
            ->willReturn([$module]);

        $this->stateManager
            ->method('isModuleActive')
            ->willReturn(true);

        $files = $this->provider->getConfigurationFiles(ConfigurationType::routes(), false);

        $expectedPath = realpath($module->getPath() . '/Config/routes.json');

        $this->assertSame([$expectedPath], $files);
    }

    public function testGetAllConfigurationFilesAggregatesPerType(): void
    {
        $servicesModule = $this->createModule('Blog', ['services' => '{"service": "blog"}']);
        $listenersModule = $this->createModule('Notifiers', ['listeners' => '{"listener": "notifier"}']);

        $this->moduleDetector
            ->method('getAllModules')
            ->willReturn([$servicesModule, $listenersModule]);

        $this->stateManager
            ->method('isModuleActive')
            ->willReturn(true);

        $filesByType = $this->provider->getAllConfigurationFiles(false);

        $this->assertSame(
            realpath($servicesModule->getPath() . '/Config/services.json'),
            $filesByType['services'][0] ?? null
        );
        $this->assertSame([], $filesByType['routes']);
        $this->assertSame([], $filesByType['commands']);
        $this->assertSame([], $filesByType['queries']);
        $this->assertSame(
            realpath($listenersModule->getPath() . '/Config/listeners.json'),
            $filesByType['listeners'][0] ?? null
        );
    }

    public function testHasModuleConfigurationDetectsExistingConfig(): void
    {
        $module = $this->createModule('Blog', ['services' => '{"service": "blog"}']);

        $this->moduleDetector
            ->method('getAllModules')
            ->willReturn([$module]);

        $this->assertTrue($this->provider->hasModuleConfiguration('Blog', ConfigurationType::services()));
        $this->assertFalse($this->provider->hasModuleConfiguration('Blog', ConfigurationType::routes()));
        $this->assertFalse($this->provider->hasModuleConfiguration('Unknown', ConfigurationType::services()));
    }

    public function testGetModuleConfigurationFileReturnsNullWhenModuleMissing(): void
    {
        $this->moduleDetector
            ->method('getAllModules')
            ->willReturn([]);

        $result = $this->provider->getModuleConfigurationFile('Missing', ConfigurationType::routes());

        $this->assertNull($result);
    }

    public function testGetModuleConfigurationFileHandlesDetectorFailures(): void
    {
        $this->moduleDetector
            ->method('getAllModules')
            ->willThrowException(new \RuntimeException('Detector failure'));

        $result = $this->provider->getModuleConfigurationFile('Any', ConfigurationType::routes());

        $this->assertNull($result);
    }

    public function testGetConfigurationFilesUsesComposerDeclaredPaths(): void
    {
        $modulePath = $this->temporaryModulesPath . '/Auth';
        $configPath = $modulePath . '/config';
        $this->createDirectory($configPath);
        file_put_contents($configPath . '/routes.json', '{"routes":[]}');

        $module = new ModuleInfo(
            'Auth',
            'cubadevops/flexi-module-auth',
            ModuleType::vendor(),
            $modulePath,
            '1.0.0',
            false,
            ['flexi' => ['config-files' => ['config/routes.json']]]
        );
        $this->moduleDetector->method('getAllModules')->willReturn([$module]);
        $this->stateManager->method('isModuleActive')->willReturn(true);

        $files = $this->provider->getConfigurationFiles(ConfigurationType::routes(), false);

        $this->assertSame([realpath($configPath . '/routes.json')], $files);
        $this->assertSame(
            realpath($configPath . '/routes.json'),
            $this->provider->getModuleConfigurationFile('Auth', ConfigurationType::routes())
        );
    }

    public function testGetModuleConfigurationFileRejectsPathsOutsideModule(): void
    {
        $modulePath = $this->temporaryModulesPath . '/Unsafe';
        $this->createDirectory($modulePath);
        file_put_contents($this->temporaryModulesPath . '/routes.json', '{"routes":[]}');

        $module = new ModuleInfo(
            'Unsafe',
            'vendor/unsafe',
            ModuleType::local(),
            $modulePath,
            null,
            false,
            ['flexi' => ['config-files' => ['../routes.json']]]
        );
        $this->moduleDetector->method('getAllModules')->willReturn([$module]);

        $this->assertNull($this->provider->getModuleConfigurationFile('Unsafe', ConfigurationType::routes()));
    }

    public function testConfigurationTypeHelpersRemainConsistent(): void
    {
        $expectedTypes = ['services', 'routes', 'commands', 'queries', 'listeners'];

        $this->assertSame($expectedTypes, ConfigurationType::getAllTypes());
        $this->assertEquals('services', ConfigurationType::services()->value());
        $this->assertEquals('routes', ConfigurationType::routes()->value());
        $this->assertEquals('commands', ConfigurationType::commands()->value());
        $this->assertEquals('queries', ConfigurationType::queries()->value());
        $this->assertEquals('listeners', ConfigurationType::listeners()->value());

        $this->assertTrue(ConfigurationType::services()->equals(ConfigurationType::services()));
        $this->assertFalse(ConfigurationType::services()->equals(ConfigurationType::routes()));
        $this->assertFalse(ConfigurationType::isValidType('invalid_type'));
    }

    private function createModule(string $name, array $configFiles): ModuleInfo
    {
        $modulePath = $this->temporaryModulesPath . '/' . $name;
        $configPath = $modulePath . '/Config';

        $this->createDirectory($configPath);

        foreach ($configFiles as $type => $payload) {
            file_put_contents($configPath . '/' . $type . '.json', $payload);
        }

        return new ModuleInfo($name, 'vendor/' . $name, ModuleType::local(), $modulePath);
    }

    private function createDirectory(string $path): void
    {
        if ('' === $path || is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Could not create directory %s', $path));
        }
    }

    private function removeDirectory(string $path): void
    {
        if ('' === $path || !is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
                continue;
            }

            unlink($fileInfo->getPathname());
        }

        rmdir($path);
    }
}
