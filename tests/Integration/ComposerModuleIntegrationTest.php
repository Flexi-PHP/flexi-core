<?php

declare(strict_types=1);

namespace Flexi\Test\Integration;

use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\EventInterface;
use Flexi\Contracts\Interfaces\EventListenerInterface;
use Flexi\Domain\Events\Event;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Infrastructure\Classes\ConfigurationFilesProvider;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Infrastructure\Classes\InMemoryCache;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\ModuleCacheManager;
use Flexi\Infrastructure\Classes\ModuleStateManager;
use Flexi\Infrastructure\Classes\ModuleStateRepository;
use Flexi\Infrastructure\Classes\ObjectBuilder;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Infrastructure\Factories\BusFactory;
use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Infrastructure\Factories\RouterFactory;
use Flexi\Application\Services\ModuleActivationPreflight;
use Flexi\Application\Commands\ActivateModuleCommand;
use Flexi\Application\UseCase\ActivateModule;
use Flexi\Test\TestData\Commands\TestCommand;
use Flexi\Test\TestData\Handlers\TestCommandHandler;
use Flexi\Test\TestData\TestDoubles\TestHttpHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ComposerModuleIntegrationTest extends TestCase
{
    private string $projectRoot;
    private string $originalWorkingDirectory;
    private ModuleStateManager $moduleStateManager;
    private HybridModuleDetector $moduleDetector;
    private ConfigurationFilesProviderInterface $configurationFilesProvider;
    private CacheInterface $cache;
    private ObjectBuilder $objectBuilder;
    private ServicesDefinitionParser $servicesParser;

    protected function setUp(): void
    {
        $this->originalWorkingDirectory = getcwd();
        $this->projectRoot = sys_get_temp_dir() . '/flexi-composer-modules-' . uniqid('', true);
        mkdir($this->projectRoot . '/var', 0777, true);
        mkdir($this->projectRoot . '/modules', 0777, true);
        mkdir($this->projectRoot . '/src/Config', 0777, true);
        foreach (glob(dirname(__DIR__, 2) . '/src/Config/*.json') as $coreConfig) {
            copy($coreConfig, $this->projectRoot . '/src/Config/' . basename($coreConfig));
        }
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'flexi/integration-fixture',
            'version' => '3.1.0',
            'extra' => ['flexi' => ['module-load-order' => ['Beta', 'Alpha']]],
        ], JSON_THROW_ON_ERROR));
        chdir($this->projectRoot);

        VendorModuleDetector::clearCache();
        LocalModuleDetector::clearCache();
        $moduleCache = new ModuleCacheManager('./var', './');
        $localDetector = new LocalModuleDetector($moduleCache);
        $vendorDetector = new VendorModuleDetector($moduleCache, './vendor');
        $this->moduleDetector = new HybridModuleDetector($localDetector, $vendorDetector);
        $this->moduleStateManager = new ModuleStateManager(
            new ModuleStateRepository('./var/modules-state.json')
        );
        $this->configurationFilesProvider = new ConfigurationFilesProvider(
            $this->moduleStateManager,
            $this->moduleDetector,
            $this->projectRoot
        );
        $this->cache = new InMemoryCache();
        $this->objectBuilder = new ObjectBuilder($this->cache);
        $this->servicesParser = new ServicesDefinitionParser($this->cache);

        ComposerModuleListener::$calls = 0;
        ComposerModuleMiddleware::$calls = 0;
        $this->createModule('acme/flexi-module-alpha', 'Alpha', true, true);
        $this->createModule('acme/flexi-module-beta', 'Beta', true, true);
    }

    protected function tearDown(): void
    {
        VendorModuleDetector::clearCache();
        LocalModuleDetector::clearCache();
        chdir($this->originalWorkingDirectory);
        $this->removeDirectory($this->projectRoot);
    }

    public function testInstalledModuleBuildRegistersServicesRoutesListenersAndHandlers(): void
    {
        $this->moduleStateManager->activateModule('Alpha');
        $routeFiles = $this->configurationFilesProvider->getConfigurationFiles(ConfigurationType::routes());
        $this->assertContains(
            $this->projectRoot . '/vendor/acme/flexi-module-alpha/config/routes.json',
            $routeFiles
        );
        $container = $this->buildContainer();

        $this->assertTrue($container->has('alpha.marker'));
        $this->assertFalse($container->has('beta.marker'));

        $router = RouterFactory::create($container);
        $request = new \GuzzleHttp\Psr7\ServerRequest('GET', 'http://localhost/composer-module');
        $response = $router->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, ComposerModuleMiddleware::$calls);

        $commandBus = BusFactory::createCommandBus($container);
        $this->assertSame(TestCommandHandler::class, $commandBus->getHandler(TestCommand::class));
        $this->assertSame('test-command-response', (string) $commandBus->execute(new TestCommand()));

        $eventBus = BusFactory::createEventBus($container);
        $eventBus->dispatch(new Event('vendor.integration', self::class));
        $this->assertSame(1, ComposerModuleListener::$calls);
    }

    public function testRouteCollisionAcrossInstalledComposerModulesFailsDuringRouterBuild(): void
    {
        $this->moduleStateManager->activateModule('Alpha');
        $this->moduleStateManager->activateModule('Beta');
        $container = $this->buildContainer();

        $eventBus = BusFactory::createEventBus($container);
        $eventBus->dispatch(new Event('vendor.integration', self::class));
        $this->assertSame(2, ComposerModuleListener::$calls);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route GET /composer-module is defined in both');

        RouterFactory::create($container);
    }

    public function testHandlerCollisionAcrossInstalledComposerModulesFailsDuringBusBuild(): void
    {
        $this->moduleStateManager->activateModule('Alpha');
        $this->moduleStateManager->activateModule('Beta');
        $container = $this->buildContainer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler ID "' . TestCommand::class . '" is defined in both');

        BusFactory::createCommandBus($container);
    }

    public function testActivationPreflightBuildsCandidateConfigurationWithoutPersistingActivation(): void
    {
        $module = $this->moduleDetector->getModuleInfo('Alpha');
        self::assertNotNull($module);

        $preflight = new ModuleActivationPreflight(
            $this->cache,
            $this->objectBuilder,
            $this->servicesParser,
            $this->configurationFilesProvider,
            $this->moduleDetector,
            $this->moduleStateManager
        );
        $preflight->validate($module);

        $this->assertFalse($this->moduleStateManager->isModuleActive('Alpha'));

        $this->moduleStateManager->activateModule('Beta');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Handler ID "' . TestCommand::class . '" is defined in both');
        $preflight->validate($module);
    }

    public function testActivationUseCaseRunsPreflightAndDispatchesCacheInvalidationEvent(): void
    {
        $cacheFile = $this->projectRoot . '/var/modules-cache.json';
        file_put_contents($cacheFile, '{}');
        $container = $this->buildContainer();
        /** @var ActivateModule $activateModule */
        $activateModule = $container->get(ActivateModule::class);

        $result = $activateModule->handle(new ActivateModuleCommand(['module_name' => 'Alpha']));
        $payload = json_decode($result->get('body'), true);

        $this->assertTrue($payload['success']);
        $freshStateManager = new ModuleStateManager(new ModuleStateRepository('./var/modules-state.json'));
        $this->assertTrue($freshStateManager->isModuleActive('Alpha'));
        $this->assertFileDoesNotExist($cacheFile);
    }

    private function buildContainer(): ContainerInterface
    {
        return (new ContainerFactory(
            $this->cache,
            $this->objectBuilder,
            $this->servicesParser,
            $this->configurationFilesProvider
        ))->getInstance();
    }

    private function createModule(string $packageName, string $moduleName, bool $addRoute, bool $withCommand): void
    {
        $vendor = explode('/', $packageName)[0];
        $package = explode('/', $packageName)[1];
        $packagePath = $this->projectRoot . '/vendor/' . $vendor . '/' . $package;
        mkdir($packagePath . '/config', 0777, true);

        file_put_contents($packagePath . '/composer.json', json_encode([
            'name' => $packageName,
            'version' => '1.0.0',
            'type' => 'flexi-module',
            'require' => ['php' => '>=7.4'],
            'extra' => ['flexi' => [
                'schema-version' => 1,
                'module-name' => $moduleName,
                'compatibility' => ['core' => '^3.0'],
                'config-files' => [
                    'config/services.json',
                    'config/routes.json',
                    'config/listeners.json',
                    'config/commands.json',
                    'config/queries.json',
                ],
                'provides' => [],
            ]],
        ], JSON_THROW_ON_ERROR));

        $services = ['services' => [[
            'name' => strtolower($moduleName) . '.marker',
            'class' => ['name' => ComposerModuleMarker::class],
        ]]];
        if ($moduleName === 'Alpha') {
            $services['services'][] = [
                'name' => 'composer.module.middleware',
                'class' => ['name' => ComposerModuleMiddleware::class],
            ];
        }
        file_put_contents($packagePath . '/config/services.json', json_encode($services, JSON_THROW_ON_ERROR));

        $routes = ['routes' => []];
        if ($addRoute) {
            $routes['routes'][] = [
                'name' => strtolower($moduleName) . '.route',
                'path' => '/composer-module',
                'method' => 'GET',
                'controller' => TestHttpHandler::class,
                'middlewares' => $moduleName === 'Alpha' ? ['composer.module.middleware'] : [],
            ];
        }
        file_put_contents($packagePath . '/config/routes.json', json_encode($routes, JSON_THROW_ON_ERROR));
        file_put_contents($packagePath . '/config/listeners.json', json_encode([[
            'event' => 'vendor.integration',
            'listeners' => [ComposerModuleListener::class],
        ]], JSON_THROW_ON_ERROR));
        file_put_contents($packagePath . '/config/commands.json', json_encode([
            'handlers' => $withCommand ? [[
                'id' => TestCommand::class,
                'handler' => TestCommandHandler::class,
            ]] : [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($packagePath . '/config/queries.json', json_encode(['handlers' => []], JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}

class ComposerModuleMarker
{
}

class ComposerModuleMiddleware implements MiddlewareInterface
{
    public static int $calls = 0;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ++self::$calls;

        return $handler->handle($request);
    }
}

class ComposerModuleListener implements EventListenerInterface
{
    public static int $calls = 0;

    public function handle(EventInterface $event): void
    {
        ++self::$calls;
    }
}
