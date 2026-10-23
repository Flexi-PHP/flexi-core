<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Factories;

use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Infrastructure\DependencyInjection\RoutesDefinitionParser;
use Flexi\Infrastructure\Factories\RouterFactory;
use Flexi\Infrastructure\Http\Route;
use Flexi\Infrastructure\Http\Router;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;

class RouterFactoryTest extends TestCase
{
    private RouterFactory $routerFactory;
    private EventBusInterface $eventBus;
    private ObjectBuilderInterface $objectBuilder;
    private ResponseFactoryInterface $responseFactory;
    private ContainerInterface $container;
    /** @var MockObject&RoutesDefinitionParser */
    private RoutesDefinitionParser $routesParser;
    /** @var MockObject&ConfigurationFilesProviderInterface */
    private ConfigurationFilesProviderInterface $configFilesProvider;

    public function setUp(): void
    {
        $this->eventBus = $this->createMock(EventBusInterface::class);
        $this->objectBuilder = $this->createMock(ObjectBuilderInterface::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->routesParser = $this->createMock(RoutesDefinitionParser::class);
        $this->configFilesProvider = $this->createMock(ConfigurationFilesProviderInterface::class);

        $this->routerFactory = new RouterFactory(
            $this->eventBus,
            $this->objectBuilder,
            $this->responseFactory,
            $this->container,
            $this->routesParser,
            $this->configFilesProvider
        );
    }

    public function tearDown(): void
    {
        // No cleanup needed when using TestData files
    }

    public function testGetInstanceAddsRoutesFromConfiguration(): void
    {
        $routeFiles = ['/tmp/routes-a.json', '/tmp/routes-b.json'];
        $firstRoute = new Route('api.health', '/health', 'App\\Http\\HealthController');
        $secondRoute = new Route('admin.dashboard', '/admin', 'App\\Http\\DashboardController');

        $this->configFilesProvider
            ->expects($this->once())
            ->method('getConfigurationFiles')
            ->with(ConfigurationType::routes())
            ->willReturn($routeFiles);

        $this->routesParser
            ->expects($this->exactly(2))
            ->method('parse')
            ->withConsecutive([$routeFiles[0]], [$routeFiles[1]])
            ->willReturnOnConsecutiveCalls([$firstRoute], [$secondRoute]);

        $router = $this->routerFactory->getInstance();

        $this->assertSame($firstRoute, $router->getByName('api.health'));
        $this->assertSame($secondRoute, $router->getByName('admin.dashboard'));
    }

    public function testRouteOverrideReplacesTheNamedRoute(): void
    {
        $original = new Route('account.profile', '/profile', 'OriginalController');
        $replacement = new Route('account.profile', '/profile', 'ModuleController', 'GET', [], [], 'account.profile');
        $this->configFilesProvider->method('getConfigurationFiles')->willReturn(['/core/routes.json', '/module/routes.json']);
        $this->routesParser->method('parse')->willReturnCallback(function (string $file) use ($original, $replacement): array {
            return $file === '/core/routes.json' ? [$original] : [$replacement];
        });

        $router = $this->routerFactory->getInstance();

        $this->assertSame($replacement, $router->getByName('account.profile'));
    }

    public function testDuplicateRouteNamesWithoutOverrideAreRejected(): void
    {
        $first = new Route('account.profile', '/profile', 'FirstController');
        $second = new Route('account.profile', '/profile', 'SecondController');
        $this->configFilesProvider->method('getConfigurationFiles')->willReturn(['/one/routes.json', '/two/routes.json']);
        $this->routesParser->method('parse')->willReturnCallback(function (string $file) use ($first, $second): array {
            return $file === '/one/routes.json' ? [$first] : [$second];
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route name "account.profile" is defined in both');
        $this->routerFactory->getInstance();
    }

    public function testRoutesCanSharePathWhenMethodsDiffer(): void
    {
        $get = new Route('item.show', '/items', 'GetController', 'GET');
        $post = new Route('item.create', '/items', 'PostController', 'POST');
        $this->configFilesProvider->method('getConfigurationFiles')->willReturn(['/routes.json']);
        $this->routesParser->method('parse')->willReturn([$get, $post]);

        $router = $this->routerFactory->getInstance();

        $this->assertSame($get, $router->getByName('item.show'));
        $this->assertSame($post, $router->getByName('item.create'));
    }

    public function testGetInstanceWithNoConfigurationFilesReturnsEmptyRouter(): void
    {
        $this->configFilesProvider
            ->expects($this->once())
            ->method('getConfigurationFiles')
            ->with(ConfigurationType::routes())
            ->willReturn([]);

        $this->routesParser
            ->expects($this->never())
            ->method('parse');

        $router = $this->routerFactory->getInstance();

        $this->assertInstanceOf(Router::class, $router);
        $this->expectException(\RuntimeException::class);
        $router->getByName('missing.route');
    }

    public function testCreateResolvesDependenciesFromContainer(): void
    {
        $eventBus = $this->createMock(EventBusInterface::class);
        $objectBuilder = $this->createMock(ObjectBuilderInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $configFilesProvider = $this->createMock(ConfigurationFilesProviderInterface::class);
        $routesParser = $this->createMock(RoutesDefinitionParser::class);

        $routeFile = '/tmp/routes-core.json';
        $route = new Route('core.status', '/status', 'App\\Http\\StatusController');

        $configFilesProvider
            ->expects($this->once())
            ->method('getConfigurationFiles')
            ->with(ConfigurationType::routes())
            ->willReturn([$routeFile]);

        $routesParser
            ->expects($this->once())
            ->method('parse')
            ->with($routeFile)
            ->willReturn([$route]);

        $container = $this->createMock(ContainerInterface::class);
        $container
            ->expects($this->exactly(5))
            ->method('get')
            ->withConsecutive(
                [EventBusInterface::class],
                [ObjectBuilderInterface::class],
                [ResponseFactoryInterface::class],
                [ConfigurationFilesProviderInterface::class],
                [RoutesDefinitionParser::class]
            )
            ->willReturnOnConsecutiveCalls(
                $eventBus,
                $objectBuilder,
                $responseFactory,
                $configFilesProvider,
                $routesParser
            );

        $router = RouterFactory::create($container);

        $this->assertSame($route, $router->getByName('core.status'));
    }
}
