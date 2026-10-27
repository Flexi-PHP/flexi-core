<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Http;

use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Infrastructure\Classes\Collection;
use Flexi\Infrastructure\Http\Route;
use Flexi\Infrastructure\Http\Router;
use Flexi\Test\TestData\TestDoubles\RouterMock;
use Flexi\Test\TestData\TestDoubles\TestHttpHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class RouterTest extends TestCase
{
    private const ROUTE_NAME = 'health';
    private const ROUTE_PATH = '/health';
    private const ROUTE_CTRL = 'TestingControllerFactory';

    private $event_bus;
    private $class_factory;
    /**
     * @var ResponseFactoryInterface|MockObject
     */
    private $response_factory;
    private \Psr\Container\ContainerInterface $container;

    private RouterMock $router;

    /**
     * @throws \JsonException
     */
    public function setUp(): void
    {
        $this->event_bus = $this->createMock(EventBusInterface::class);
        $this->class_factory = $this->createMock(ObjectBuilderInterface::class);
        $this->response_factory = $this->createMock(ResponseFactoryInterface::class);
        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);

        $this->router = new RouterMock($this->event_bus, $this->class_factory, $this->response_factory, $this->container);

        $route = new Route(
            self::ROUTE_NAME,
            self::ROUTE_PATH,
            self::ROUTE_CTRL,
            'GET',
            ['test' => 'param']
        );
        $this->router->addRoute($route);
    }

    public function testAddRouteRejectsDuplicateName(): void
    {
        $route = new Route(
            self::ROUTE_NAME,
            self::ROUTE_PATH,
            self::ROUTE_CTRL,
            'GET',
            ['test' => 'param']
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route name "health" is already registered');
        $this->router->addRoute($route);
    }

    public function testDispatch(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        $route = new Route(
            self::ROUTE_NAME,
            self::ROUTE_PATH,
            self::ROUTE_CTRL,
            'GET',
            ['test' => 'param']
        );
        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $testHandler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);
        $testHandler->expects($this->once())->method('handle')->with($serverRequestMock)->willReturn($responseInterfaceMock);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn(self::ROUTE_PATH);

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('GET');

        $this->event_bus->expects($this->exactly(2))
            ->method('dispatch')->willReturnSelf();

        $this->class_factory->expects($this->once())
            ->method('build')->willReturn($testHandler);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertNotEmpty($response);
        $this->assertFalse($this->router->redirect_to_not_found_spy);
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     * @throws \JsonException
     */
    public function testDispatchNoRoutes(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        $emptyRouter = new Router(
            $this->event_bus, $this->class_factory, $this->response_factory, $this->container
        );

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn(self::ROUTE_PATH);

        $serverRequestMock->expects($this->once())->method('getMethod')->willReturn('GET');
        $response = $this->createMock(ResponseInterface::class);
        $this->response_factory->expects($this->once())->method('createResponse')->with(404)->willReturn($response);

        $this->assertSame($response, $emptyRouter->dispatch($serverRequestMock));
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     */
    public function testDispatchParameterRequired(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        $requiredParam = 'user';
        $route = new Route(
            'health.required',
            '/health-required',
            self::ROUTE_CTRL,
            'GET',
            [
                0 => [
                    'required' => true,
                    'name' => $requiredParam,
                ],
            ]
        );
        $this->router->addRoute($route);

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/health-required');

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('GET');

        $response = $this->createMock(ResponseInterface::class);
        $this->response_factory->expects($this->once())->method('createResponse')->with(400)->willReturn($response);

        $this->assertSame($response, $this->router->dispatch($serverRequestMock));
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     */
    public function testDispatchInvalidMethod(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $invalidMethod = 'POST';

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn(self::ROUTE_PATH);

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn($invalidMethod);

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('withHeader')->with('Allow', 'GET')->willReturnSelf();
        $this->response_factory->expects($this->once())->method('createResponse')->with(405)->willReturn($response);

        $this->assertSame($response, $this->router->dispatch($serverRequestMock));
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     */
    public function testDispatchDirectToNotFound(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);
        $responseInterfaceMock->expects($this->once())
            ->method('getStatusCode')->willReturn(404);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/invalid');

        $this->response_factory->expects($this->once())
            ->method('createResponse')->willReturn($responseInterfaceMock);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testGetByNameDoesNotExist(): void
    {
        $invalidRouteName = '/invalid-route-name';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Route '{$invalidRouteName}' not found");

        $this->router->getByName($invalidRouteName);
    }

    public function testGetByNameExistingRoute(): void
    {
        $route = $this->router->getByName(self::ROUTE_NAME);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertEquals(self::ROUTE_NAME, $route->getName());
        $this->assertEquals(self::ROUTE_PATH, $route->getPath());
        $this->assertEquals(self::ROUTE_CTRL, $route->getController());
    }

    public function testGetUrlBase(): void
    {
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_HOST'] = 'flexi-test.local';

        $urlBase = Router::getUrlBase();

        $this->assertEquals('https://flexi-test.local', $urlBase);
    }

    public function testGetUrlBaseHttp(): void
    {
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['HTTP_HOST'] = 'localhost:8080';

        $urlBase = Router::getUrlBase();

        $this->assertEquals('http://localhost:8080', $urlBase);
    }

    public function testAddRouteReturnsRouter(): void
    {
        $route = new Route(
            'api-route',
            '/api/test',
            'ApiController',
            'POST',
            []
        );

        $result = $this->router->addRoute($route);

        $this->assertSame($this->router, $result);
        $this->assertEquals(2, $this->router->route_counter); // We already have one route in setUp
    }

    public function testDispatchWithMiddlewares(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        // Create route with middlewares
        $routeWithMiddleware = new Route(
            'middleware-route',
            '/middleware',
            'MiddlewareController',
            'GET',
            [],
            ['AuthMiddleware', 'LoggingMiddleware']
        );
        $this->router->addRoute($routeWithMiddleware);

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $testHandler = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);
        $testHandler->expects($this->once())->method('handle')->with($serverRequestMock)->willReturn($responseInterfaceMock);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/middleware');

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('GET');

        $this->event_bus->expects($this->exactly(2))
            ->method('dispatch')->willReturnSelf();

        $authMiddleware = $this->createMock(\Psr\Http\Server\MiddlewareInterface::class);
        $loggingMiddleware = $this->createMock(\Psr\Http\Server\MiddlewareInterface::class);
        foreach ([$authMiddleware, $loggingMiddleware] as $middleware) {
            $middleware->expects($this->once())->method('process')->willReturnCallback(
                static function ($request, $handler) {
                    return $handler->handle($request);
                }
            );
        }
        $this->container->expects($this->exactly(2))
            ->method('get')
            ->withConsecutive(['AuthMiddleware'], ['LoggingMiddleware'])
            ->willReturnOnConsecutiveCalls($authMiddleware, $loggingMiddleware);
        $this->class_factory->expects($this->once())
            ->method('build')
            ->with($this->container, 'MiddlewareController')
            ->willReturn($testHandler);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDispatchWithOptionalParameters(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';

        // Create route with optional parameters
        $routeWithParams = new Route(
            'params-route',
            '/params',
            'ParamsController',
            'GET',
            [
                [
                    'required' => false,
                    'name' => 'optional_param',
                ]
            ]
        );
        $this->router->addRoute($routeWithParams);

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $testHandler = new TestHttpHandler();
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);

        $testHandler->setMockResponse($responseInterfaceMock);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/params');

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('GET');

        $this->event_bus->expects($this->exactly(2))
            ->method('dispatch')->willReturnSelf();

        $this->class_factory->expects($this->once())
            ->method('build')->willReturn($testHandler);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDispatchWithValidRequiredParameter(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_GET['user_id'] = '123'; // Set required parameter

        $routeWithRequiredParam = new Route(
            'user-route',
            '/user',
            'UserController',
            'GET',
            [
                [
                    'required' => true,
                    'name' => 'user_id',
                ]
            ]
        );
        $this->router->addRoute($routeWithRequiredParam);

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $testHandler = new TestHttpHandler();
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);

        $testHandler->setMockResponse($responseInterfaceMock);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/user');

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('GET');

        $this->event_bus->expects($this->exactly(2))
            ->method('dispatch')->willReturnSelf();

        $this->class_factory->expects($this->once())
            ->method('build')->willReturn($testHandler);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertInstanceOf(ResponseInterface::class, $response);

        // Cleanup
        unset($_GET['user_id']);
    }

    public function testDispatchWithPostParameter(): void
    {
        $_SERVER['HTTP_HOST'] = 'flexi';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_POST['form_data'] = 'test'; // Set POST parameter

        $routeWithPostParam = new Route(
            'post-route',
            '/submit',
            'SubmitController',
            'POST',
            [
                [
                    'required' => true,
                    'name' => 'form_data',
                ]
            ]
        );
        $this->router->addRoute($routeWithPostParam);

        $uriInterfaceMock = $this->createMock(UriInterface::class);
        $testHandler = new TestHttpHandler();
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $responseInterfaceMock = $this->createMock(ResponseInterface::class);

        $testHandler->setMockResponse($responseInterfaceMock);

        $serverRequestMock->expects($this->exactly(2))
            ->method('getUri')->willReturn($uriInterfaceMock);

        $uriInterfaceMock->expects($this->once())
            ->method('getPath')->willReturn('/submit');

        $serverRequestMock->expects($this->once())
            ->method('getMethod')->willReturn('POST');

        $this->event_bus->expects($this->exactly(2))
            ->method('dispatch')->willReturnSelf();

        $this->class_factory->expects($this->once())
            ->method('build')->willReturn($testHandler);

        $response = $this->router->dispatch($serverRequestMock);

        $this->assertInstanceOf(ResponseInterface::class, $response);

        // Cleanup
        unset($_POST['form_data']);
    }

    /**
     * Tests loading routes from JSON file
     * @throws \JsonException
     */
    /**
     * DEPRECATED: loadRoutesFile method has been removed
     */
    /*
    public function testLoadRoutesFile(): void
    {
        // Create temporary routes file
        $tempFile = tempnam(sys_get_temp_dir(), 'routes');
        $routesData = [
            'routes' => [
                [
                    'name' => 'api-users',
                    'path' => '/api/users',
                    'controller' => 'UsersController',
                    'method' => 'GET',
                    'parameters' => [['name' => 'page', 'required' => false]],
                    'middlewares' => ['AuthMiddleware']
                ],
                [
                    'name' => 'api-posts',
                    'path' => '/api/posts',
                    'controller' => 'PostsController',
                    'method' => 'POST'
                ]
            ]
        ];
        file_put_contents($tempFile, json_encode($testData, JSON_THROW_ON_ERROR));

        // DEPRECATED: loadRoutesFile method has been removed
        // $this->router->loadRoutesFile($tempFile);

        // Test through reflection should verify glob detection
        // Verify routes were loaded
        $usersRoute = $this->router->getByName('api-users');
        $this->assertEquals('/api/users', $usersRoute->getPath());
        $this->assertEquals('UsersController', $usersRoute->getController());

        $postsRoute = $this->router->getByName('api-posts');
        $this->assertEquals('/api/posts', $postsRoute->getPath());
        $this->assertEquals('PostsController', $postsRoute->getController());

        unlink($tempFile);
    }
    */

    /**
     * Tests loading routes with glob patterns
     * DEPRECATED: loadRoutesFile method has been removed
     * @throws \JsonException
     */
    /*
    public function testLoadRoutesFileWithGlobPattern(): void
    {
        // Create temporary directory and route files
        $tempDir = sys_get_temp_dir() . '/test_routes_' . uniqid();
        mkdir($tempDir);

        $routeFile1 = $tempDir . '/module1.json';
        $routeFile2 = $tempDir . '/module2.json';

        file_put_contents($routeFile1, json_encode([
            'routes' => [
                ['name' => 'module1-route', 'path' => '/module1', 'controller' => 'Module1Controller', 'method' => 'GET']
            ]
        ], JSON_THROW_ON_ERROR));

        file_put_contents($routeFile2, json_encode([
            'routes' => [
                ['name' => 'module2-route', 'path' => '/module2', 'controller' => 'Module2Controller', 'method' => 'GET']
            ]
        ], JSON_THROW_ON_ERROR));

        // Create main routes file with glob pattern
        $mainFile = tempnam(sys_get_temp_dir(), 'main_routes');
        $mainData = [
            'routes' => [
                ['name' => 'main-route', 'path' => '/main', 'controller' => 'MainController', 'method' => 'GET'],
                ['glob' => $tempDir . '/*.json']
            ]
        ];
        file_put_contents($mainFile, json_encode($mainData, JSON_THROW_ON_ERROR));

        $this->router->loadRoutesFile($mainFile);

        // Verify all routes were loaded
        $mainRoute = $this->router->getByName('main-route');
        $this->assertEquals('/main', $mainRoute->getPath());

        $module1Route = $this->router->getByName('module1-route');
        $this->assertEquals('/module1', $module1Route->getPath());

        $module2Route = $this->router->getByName('module2-route');
        $this->assertEquals('/module2', $module2Route->getPath());

        // Cleanup
        unlink($routeFile1);
        unlink($routeFile2);
        rmdir($tempDir);
        unlink($mainFile);
    }
    */

    /**
     * Tests loadGlobRoutes method directly
     * DEPRECATED: loadGlobRoutes method has been removed
     * @throws \JsonException
     */
    /*
    public function testLoadGlobRoutes(): void
    {
        // Create temporary directory and route files
        $tempDir = sys_get_temp_dir() . '/test_glob_routes_' . uniqid();
        mkdir($tempDir);

        $globFile1 = $tempDir . '/glob1.json';
        $globFile2 = $tempDir . '/glob2.json';

        file_put_contents($globFile1, json_encode([
            'routes' => [
                ['name' => 'glob1-route', 'path' => '/glob1', 'controller' => 'Glob1Controller', 'method' => 'GET']
            ]
        ], JSON_THROW_ON_ERROR));

        file_put_contents($globFile2, json_encode([
            'routes' => [
                ['name' => 'glob2-route', 'path' => '/glob2', 'controller' => 'Glob2Controller', 'method' => 'POST']
            ]
        ], JSON_THROW_ON_ERROR));

                file_put_contents($file2, json_encode($routes2, JSON_THROW_ON_ERROR));

        // DEPRECATED: loadGlobRoutes method has been removed
        // $this->router->loadGlobRoutes($tempDir . '/*.json');

        // Verify routes from both files were loaded

        // Verify routes were loaded
        $glob1Route = $this->router->getByName('glob1-route');
        $this->assertEquals('/glob1', $glob1Route->getPath());

        $glob2Route = $this->router->getByName('glob2-route');
        $this->assertEquals('/glob2', $glob2Route->getPath());

        // Cleanup
        unlink($globFile1);
        unlink($globFile2);
        rmdir($tempDir);
    }
    */

    /**
     * Tests handleNotFound method default behavior
     * @throws \JsonException
     */
    public function testHandleNotFoundDefaultBehavior(): void
    {
        /** @var ServerRequestInterface&\PHPUnit\Framework\MockObject\MockObject $serverRequestMock */
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $default404Mock = $this->createMock(ResponseInterface::class);
        $bodyMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $requestedPath = '/nonexistent';

        $default404Mock->method('getBody')->willReturn($bodyMock);
        $bodyMock->expects($this->once())->method('write')->with('404 - Not Found');

        // Create a new router for this test with mocked response factory
        /** @var ResponseFactoryInterface&\PHPUnit\Framework\MockObject\MockObject $responseFactoryMock */
        $responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);
        $responseFactoryMock->expects($this->once())
            ->method('createResponse')
            ->with(404)
            ->willReturn($default404Mock);

        $testRouter = new Router(
            $this->event_bus, $this->class_factory, $responseFactoryMock, $this->container
        );

        $this->event_bus->expects($this->once())
            ->method('dispatch')
            ->willReturnArgument(0); // Return event as-is (no response set)

        $result = $testRouter->handleNotFound($serverRequestMock, $requestedPath);

        $this->assertSame($default404Mock, $result);
    }

    /**
     * Tests private isGlob method through behavior
     * DEPRECATED: loadRoutesFile method has been removed
     */
    /*
    public function testIsGlobBehavior(): void
    {
        // Test glob detection indirectly through loadRoutesFile behavior
        $tempFile = tempnam(sys_get_temp_dir(), 'routes_glob_test');
        $routesData = [
            'routes' => [
                ['name' => 'normal', 'path' => '/normal', 'controller' => 'NormalController', 'method' => 'GET'],
                ['glob' => '/nonexistent/path/*.json'] // This should trigger glob logic
            ]
        ];
        file_put_contents($tempFile, json_encode($routesData, JSON_THROW_ON_ERROR));

        // This should not throw an exception even with nonexistent glob path
        $this->router->loadRoutesFile($tempFile);

        // Normal route should be loaded
        $normalRoute = $this->router->getByName('normal');
        $this->assertEquals('/normal', $normalRoute->getPath());

        unlink($tempFile);
    }
    */

    /**
     * Tests assertMethodIsAllowedForRoute through processRequest
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function testProcessRequestMethodValidation(): void
    {
        $route = new Route('test-route', '/test', 'TestController', 'PUT', []);

        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $serverRequestMock->method('getMethod')->willReturn('GET'); // Wrong method

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())->method('withHeader')->with('Allow', 'PUT')->willReturnSelf();
        $this->response_factory->expects($this->once())->method('createResponse')->with(405)->willReturn($response);

        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('processRequest');
        $method->setAccessible(true);
        $this->assertSame($response, $method->invoke($this->router, $serverRequestMock, $route));
    }

    /**
     * Tests checkRequiredParamsWereSend through processRequest
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function testProcessRequestParameterValidation(): void
    {
        $route = new Route('test-route', '/test', 'TestController', 'GET', [
            ['name' => 'required_param', 'required' => true]
        ]);

        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $serverRequestMock->method('getMethod')->willReturn('GET');

        $response = $this->createMock(ResponseInterface::class);
        $this->response_factory->expects($this->once())->method('createResponse')->with(400)->willReturn($response);

        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('processRequest');
        $method->setAccessible(true);
        $this->assertSame($response, $method->invoke($this->router, $serverRequestMock, $route));
    }

    /**
     * Tests executeStack method through reflection
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function testExecuteStackWithoutMiddlewares(): void
    {
        $route = new Route('test-route', '/test', 'TestController', 'GET', []);
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        $handlerMock = $this->createMock(\Psr\Http\Server\RequestHandlerInterface::class);
        $responseMock = $this->createMock(ResponseInterface::class);

        $this->class_factory->expects($this->once())
            ->method('build')
            ->with($this->container, 'TestController')
            ->willReturn($handlerMock);

        $handlerMock->expects($this->once())
            ->method('handle')
            ->with($serverRequestMock)
            ->willReturn($responseMock);

        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('executeStack');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, $route, $serverRequestMock);

        $this->assertSame($responseMock, $result);
    }

    /**
     * Tests assertThatRouteCollectionIsNotEmpty method
     * @throws \ReflectionException
     */
    public function testEmptyRouteTableIsAllowed(): void
    {
        // Create a router without routes
        $emptyRouter = new Router(
            $this->event_bus, $this->class_factory, $this->response_factory, $this->container
        );

        $this->assertInstanceOf(Router::class, $emptyRouter);
    }

    /**
     * Tests assertRouteNameExist method
     * @throws \ReflectionException
     */
    public function testAssertRouteNameExist(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('assertRouteNameExist');
        $method->setAccessible(true);

        // Should not throw for existing route
        $method->invoke($this->router, self::ROUTE_NAME);

        // Should throw for non-existing route
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Route 'nonexistent' not found");

        $method->invoke($this->router, 'nonexistent');
    }

    /**
     * Tests handleNotFound with custom response from event listener
     * This covers the line: return $event->getResponse();
     * @throws \JsonException
     */
    public function testHandleNotFoundWithCustomEventResponse(): void
    {
        // Create separate mocks for this test
        /** @var EventBusInterface&\PHPUnit\Framework\MockObject\MockObject $eventBusMock */
        $eventBusMock = $this->createMock(EventBusInterface::class);
        /** @var ResponseFactoryInterface&\PHPUnit\Framework\MockObject\MockObject $responseFactoryMock */
        $responseFactoryMock = $this->createMock(ResponseFactoryInterface::class);

        $testRouter = new Router(
            $eventBusMock,
            $this->class_factory,
            $responseFactoryMock,
            $this->container
        );

        /** @var ServerRequestInterface&\PHPUnit\Framework\MockObject\MockObject $serverRequestMock */
        $serverRequestMock = $this->createMock(ServerRequestInterface::class);
        /** @var ResponseInterface&\PHPUnit\Framework\MockObject\MockObject $customResponseMock */
        $customResponseMock = $this->createMock(ResponseInterface::class);
        $requestedPath = '/custom-404';

        // Mock event bus to capture and modify the dispatched event
        $eventBusMock->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function($event) use ($customResponseMock) {
                // Simulate event listener setting a custom response
                /** @var \Flexi\Domain\Events\RouteNotFoundEvent $event */
                $event->setResponse($customResponseMock);
                return $event;
            });

        // Response factory should NOT be called since we use custom response
        $responseFactoryMock->expects($this->never())->method('createResponse');

        $result = $testRouter->handleNotFound($serverRequestMock, $requestedPath);

        // Should return the custom response from event, not the default 404
        $this->assertSame($customResponseMock, $result);
    }
}
