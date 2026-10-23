<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Factories;

use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Infrastructure\Factories\CacheFactoryInterface;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Flexi\Infrastructure\Classes\ModuleCacheManager;
use Flexi\Infrastructure\Factories\DefaultCacheFactory;
use Flexi\Infrastructure\DependencyInjection\Container;
use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Infrastructure\Classes\InMemoryCache;
use Flexi\Infrastructure\Classes\ObjectBuilder;
use Flexi\Infrastructure\Classes\Configuration;
use Flexi\Infrastructure\Classes\ConfigurationRepository;
use Flexi\Infrastructure\Classes\ModuleDependencyValidator;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use PHPUnit\Framework\TestCase;

class ContainerFactoryTest extends TestCase
{
    /** @var CacheInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    /** @var ObjectBuilderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $objectBuilder;

    /** @var ServicesDefinitionParser|\PHPUnit\Framework\MockObject\MockObject */
    private $servicesDefinitionParser;

    /** @var ConfigurationFilesProviderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $configFilesProvider;

    protected function setUp(): void
    {
        // Use mocks for all dependencies
        $this->cache = $this->createMock(CacheInterface::class);
        $this->objectBuilder = $this->createMock(ObjectBuilderInterface::class);
        $this->servicesDefinitionParser = $this->createMock(ServicesDefinitionParser::class);
        $this->configFilesProvider = $this->createMock(ConfigurationFilesProviderInterface::class);

        // Configure cache mock to return appropriate defaults
        $this->cache
            ->method('get')
            ->willReturnCallback(function ($key, $default = null) {
                if ($key === 'service_definitions') {
                    return [];
                }
                return $default;
            });

        // Configure cache mock for set operations
        $this->cache
            ->method('set')
            ->willReturn(true);

    }

    public function testConstruct(): void
    {
        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $this->assertInstanceOf(ContainerFactory::class, $factory);
    }

    public function testGetInstanceWithoutFile(): void
    {
        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $container = $factory->getInstance();

        $this->assertInstanceOf(Container::class, $container);
    }

    public function testValidatesModuleDependenciesBeforeBuildingContainer(): void
    {
        $validator = $this->getMockBuilder(ModuleDependencyValidator::class)
            ->disableOriginalConstructor()
            ->getMock();
        $validator->expects($this->once())->method('validate');
        $this->configFilesProvider->method('getAllConfigurationFiles')->willReturn([]);

        $factory = new ContainerFactory(
            $this->cache,
            $this->objectBuilder,
            $this->servicesDefinitionParser,
            $this->configFilesProvider,
            $validator
        );

        $this->assertInstanceOf(Container::class, $factory->getInstance());
    }

    public function testGetInstanceWithFile(): void
    {
        // Configure the config files provider to return the test file
        $this->configFilesProvider
            ->method('getConfigurationFiles')
            ->willReturn(['/var/www/html/var/test-services.json']);

        // Mock the services parser to return test services when called
        $testServices = [
            'test-service' => $this->createMock(\Flexi\Infrastructure\DependencyInjection\Service::class)
        ];

        $this->servicesDefinitionParser
            ->method('parse')
            ->willReturn($testServices);

        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $container = $factory->getInstance();

        $this->assertInstanceOf(Container::class, $container);
    }

    public function testServiceDecoratorReplacesTargetAndReceivesInnerService(): void
    {
        $files = ['/modules/Mailer/Config/services.json'];
        $this->configFilesProvider->method('getAllConfigurationFiles')->willReturn([]);
        $this->configFilesProvider->expects($this->once())->method('getConfigurationFiles')->willReturn($files);
        $this->servicesDefinitionParser->method('parse')->with($files[0])->willReturn([
            'mailer' => ['class' => ['name' => ContainerFactoryBaseMailer::class]],
            'logged_mailer' => [
                'decorates' => 'mailer',
                'class' => ['name' => ContainerFactoryLoggedMailer::class, 'arguments' => ['@.inner']],
            ],
            'audited_mailer' => [
                'decorates' => 'logged_mailer',
                'class' => ['name' => ContainerFactoryAuditedMailer::class, 'arguments' => ['@.inner']],
            ],
        ]);

        $cache = new \Flexi\Infrastructure\Classes\InMemoryCache();
        $builder = new \Flexi\Infrastructure\Classes\ObjectBuilder($cache);
        $factory = new ContainerFactory($cache, $builder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $container = $factory->getInstance();

        $this->assertSame('logged_mailer', $cache->get('service_definitions')['mailer'] ?? null);
        $this->assertInstanceOf(ContainerFactoryAuditedMailer::class, $container->get('mailer'));
        $this->assertSame('audited:logged:base', $container->get('mailer')->send());
    }

    public function testDuplicateServiceIdsAcrossFilesAreRejected(): void
    {
        $files = ['/modules/One/Config/services.json', '/modules/Two/Config/services.json'];
        $this->configFilesProvider->method('getAllConfigurationFiles')->willReturn([]);
        $this->configFilesProvider->expects($this->once())->method('getConfigurationFiles')->willReturn($files);
        $this->servicesDefinitionParser->method('parse')->willReturn(['shared' => 'TargetService']);

        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service ID "shared" is defined in both');
        $factory->getInstance();
    }

    public function testValidatesAllConfigurationFilesBeforeRegisteringServices(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'invalid-route-');
        file_put_contents($file, json_encode([
            'routes' => [[
                'name' => 'health',
                'path' => '/health',
                'method' => 'FETCH',
                'controller' => 'HealthController',
            ]],
        ]));

        $this->configFilesProvider
            ->method('getAllConfigurationFiles')
            ->willReturn(['routes' => [$file]]);
        $this->servicesDefinitionParser
            ->expects($this->never())
            ->method('parse');

        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid configuration');
            $factory->getInstance();
        } finally {
            unlink($file);
        }
    }

    public function testCreateDefaultWithoutParameters(): void
    {
        $container = ContainerFactory::createDefault();

        $this->assertInstanceOf(Container::class, $container);
    }

    public function testCreateDefaultCacheModuleNotInstalled(): void
    {
        // This test relies on the fact that cache module is not installed by default
        // The method should fall back to InMemoryCache
        $container = ContainerFactory::createDefault();

        $this->assertInstanceOf(Container::class, $container);
    }

    public function testCreateDefaultWithInvalidComposerJson(): void
    {
        // Create a temporary invalid composer.json
        $originalComposerPath = './composer.json';
        $backupPath = './composer.json.backup';
        $invalidComposerPath = './composer.json';

        // Backup original if exists
        if (file_exists($originalComposerPath)) {
            rename($originalComposerPath, $backupPath);
        }

        // Create invalid JSON
        file_put_contents($invalidComposerPath, '{"invalid": json}');

        $container = ContainerFactory::createDefault();

        $this->assertInstanceOf(Container::class, $container);

        // Restore original composer.json
        unlink($invalidComposerPath);
        if (file_exists($backupPath)) {
            rename($backupPath, $originalComposerPath);
        }
    }

    public function testCreateDefaultWithNoComposerJson(): void
    {
        // Create a temporary directory without composer.json
        $originalDir = getcwd();
        $tempDir = '/tmp/test-no-composer-' . uniqid();

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        chdir($tempDir);

        $container = ContainerFactory::createDefault();

        $this->assertInstanceOf(Container::class, $container);

        // Restore original directory
        chdir($originalDir);
        rmdir($tempDir);
    }

    public function testGetInstanceWithMockedDependencies(): void
    {
        // Test getInstance method behavior with mocked dependencies
        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);

        // Test without file - should create Container with mocked dependencies
        $container = $factory->getInstance();
        $this->assertInstanceOf(Container::class, $container);
    }

    public function testGetInstanceWithFileAndMockedDependencies(): void
    {
        // Mock the services parser to return test services
        $mockService = $this->createMock(\Flexi\Infrastructure\DependencyInjection\Service::class);
        $testServices = [
            'test-service-mocked' => $mockService
        ];

        $this->servicesDefinitionParser
            ->method('parse')
            ->willReturn($testServices);

        // Test with file - should parse services and add to container
        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $container = $factory->getInstance();

        $this->assertInstanceOf(Container::class, $container);
        // Note: Testing service registration may require specific Container behavior
        // For now, we'll just verify the container was created successfully
    }

    public function testEdgeCasesWithMocks(): void
    {
        // Test createDefault without parameters
        $container = ContainerFactory::createDefault();
        $this->assertInstanceOf(Container::class, $container);

        // Test getInstance without parameters
        $factory = new ContainerFactory($this->cache, $this->objectBuilder, $this->servicesDefinitionParser, $this->configFilesProvider);
        $container = $factory->getInstance();
        $this->assertInstanceOf(Container::class, $container);
    }

    public function testHybridModuleDetectorImplementation(): void
    {
        // Test the real implementation of HybridModuleDetector
        $cacheManager = new ModuleCacheManager();
        $localDetector = new LocalModuleDetector($cacheManager, './tests/fixtures/modules');
        $vendorDetector = $this->createMock(VendorModuleDetector::class);
        $detector = new HybridModuleDetector($localDetector, $vendorDetector);

        // This should return false for non-existent modules
        $this->assertFalse($detector->isModuleInstalled('non-existent-module'));

        // Test interface methods are implemented
        $modules = $detector->getAllModules();
        $this->assertIsArray($modules);

        $stats = $detector->getModuleStatistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total', $stats);
    }

    public function testDefaultCacheFactoryImplementation(): void
    {
        // Test DefaultCacheFactory with module not installed
        $mockModuleDetector = $this->createMock(ModuleDetectorInterface::class);
        $mockModuleDetector
            ->method('isModuleInstalled')
            ->willReturn(false);

        $mockConfiguration = $this->createMock(Configuration::class);
        $cacheFactory = new DefaultCacheFactory($mockModuleDetector, $mockConfiguration);
        $cache = $cacheFactory->createCache();

        $this->assertInstanceOf(InMemoryCache::class, $cache);
    }

    public function testDefaultCacheFactoryWithCacheModuleInstalled(): void
    {
        // Test DefaultCacheFactory when cache module is "installed" but fails
        $mockModuleDetector = $this->createMock(ModuleDetectorInterface::class);
        $mockModuleDetector
            ->method('isModuleInstalled')
            ->willReturn(true);

        $mockConfiguration = $this->createMock(Configuration::class);
        $cacheFactory = new DefaultCacheFactory($mockModuleDetector, $mockConfiguration);

        // Should fallback to InMemoryCache when cache module fails to load
        $cache = $cacheFactory->createCache();
        $this->assertInstanceOf(InMemoryCache::class, $cache);
    }

    public function testHybridModuleDetectorCacheHandling(): void
    {
        // Test caching behavior
        $cacheManager = new ModuleCacheManager();
        $localDetector = new LocalModuleDetector($cacheManager, './tests/fixtures/modules');
        $vendorDetector = $this->createMock(VendorModuleDetector::class);
        $detector = new HybridModuleDetector($localDetector, $vendorDetector);

        // Multiple calls should use cached results
        $result1 = $detector->isModuleInstalled('nonexistent');
        $result2 = $detector->isModuleInstalled('nonexistent');

        $this->assertEquals($result1, $result2, 'Caching should return consistent results');

        // Test cache clearing
        $detector->clearCache();
        $result3 = $detector->isModuleInstalled('nonexistent');
        $this->assertEquals($result1, $result3, 'Results should be consistent after cache clear');
    }

    public function testHybridModuleDetectorWithDifferentModules(): void
    {
        $cacheManager = new ModuleCacheManager();
        $localDetector = new LocalModuleDetector($cacheManager, './tests/fixtures/modules');
        $vendorDetector = $this->createMock(VendorModuleDetector::class);
        $detector = new HybridModuleDetector($localDetector, $vendorDetector);

        // Test interface compliance for various module names
        $moduleNames = ['auth', 'cache', 'session', 'user-management'];

        foreach ($moduleNames as $moduleName) {
            // Test isModuleInstalled method
            $isInstalled = $detector->isModuleInstalled($moduleName);
            $this->assertIsBool($isInstalled);

            // Test getModuleInfo method
            $moduleInfo = $detector->getModuleInfo($moduleName);
            $this->assertTrue($moduleInfo === null || is_object($moduleInfo));
        }
    }
}

interface ContainerFactoryMailerInterface
{
    public function send(): string;
}

class ContainerFactoryBaseMailer implements ContainerFactoryMailerInterface
{
    public function send(): string
    {
        return 'base';
    }
}

class ContainerFactoryLoggedMailer implements ContainerFactoryMailerInterface
{
    private ContainerFactoryMailerInterface $inner;

    public function __construct(ContainerFactoryMailerInterface $inner)
    {
        $this->inner = $inner;
    }

    public function send(): string
    {
        return 'logged:'.$this->inner->send();
    }
}

class ContainerFactoryAuditedMailer implements ContainerFactoryMailerInterface
{
    private ContainerFactoryMailerInterface $inner;

    public function __construct(ContainerFactoryMailerInterface $inner)
    {
        $this->inner = $inner;
    }

    public function send(): string
    {
        return 'audited:'.$this->inner->send();
    }
}
