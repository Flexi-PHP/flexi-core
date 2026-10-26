<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Http;

use Flexi\Contracts\Classes\ObjectCollection;
use Flexi\Contracts\Classes\Traits\CacheKeyGeneratorTrait;
use Flexi\Contracts\Classes\Traits\GlobFileReader;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\CollectionInterface;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Domain\Events\Event;
use Flexi\Domain\Events\RouteNotFoundEvent;
use Flexi\Infrastructure\Http\Route;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Router
{
    use JsonFileReader;
    use GlobFileReader;

    protected ObjectCollection $routes_indexed_by_name;
    protected ObjectCollection $routes_indexed_by_path;
    protected EventBusInterface $event_bus;
    protected ObjectBuilderInterface $class_factory;
    protected ResponseFactoryInterface $response_factory;
    protected ContainerInterface $container;

    public function __construct(
        EventBusInterface $event_bus,
        ObjectBuilderInterface $class_factory,
        ResponseFactoryInterface $response_factory,
        ContainerInterface $container
    ) {
        $this->event_bus = $event_bus;
        $this->class_factory = $class_factory;
        $this->response_factory = $response_factory;
        $this->container = $container;
        $this->routes_indexed_by_name = new ObjectCollection(Route::class);
        $this->routes_indexed_by_path = new ObjectCollection(Route::class);
    }


    /**
     * @return static
     */
    public function addRoute(Route $route): self
    {
        if ($this->routes_indexed_by_name->offsetExists($route->getName())) {
            throw new \RuntimeException(sprintf('Route name "%s" is already registered.', $route->getName()));
        }
        $pathKey = $this->getPathKey($route->getPath(), $route->getMethod());
        if ($this->routes_indexed_by_path->offsetExists($pathKey)) {
            throw new \RuntimeException(sprintf(
                'Route %s %s is already registered.',
                $route->getMethod(),
                $route->getPath()
            ));
        }
        $this->routes_indexed_by_name->add($route, $route->getName());
        $this->routes_indexed_by_path->add($route, $pathKey);

        return $this;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function dispatch(
        ServerRequestInterface $request
    ): ResponseInterface {
        $request_path = $request->getUri()->getPath();
        $event = new Event('core.onRequest', __CLASS__, ['request' => $request->getUri()->__toString()]);
        $this->event_bus->dispatch($event);

        $requestMethod = $request->getMethod();
        $methodKey = $this->getPathKey($request_path, $requestMethod);
        if (!$this->routes_indexed_by_path->offsetExists($methodKey)) {
            if ($this->hasPath($request_path)) {
                return $this->createMethodNotAllowedResponse($request_path);
            }
            return $this->handleNotFound($request, $request_path);
        }
        /** @var Route $route */
        $route = $this->routes_indexed_by_path->get($methodKey);

        return $this->processRequest($request, $route, $requestMethod);
    }

    private function getPathKey(string $path, string $method): string
    {
        return $method.' '.$path;
    }

    private function hasPath(string $path): bool
    {
        foreach ($this->routes_indexed_by_path as $route) {
            if ($route->getPath() === $path) {
                return true;
            }
        }

        return false;
    }

    private function createMethodNotAllowedResponse(string $path, ?string $fallbackMethod = null): ResponseInterface
    {
        $allowedMethods = [];
        foreach ($this->routes_indexed_by_path as $route) {
            if ($route->getPath() === $path) {
                $allowedMethods[] = $route->getMethod();
            }
        }
        if (!$allowedMethods && $fallbackMethod !== null) {
            $allowedMethods[] = $fallbackMethod;
        }

        return $this->response_factory
            ->createResponse(405)
            ->withHeader('Allow', implode(', ', $allowedMethods));
    }

    public function handleNotFound(
        ServerRequestInterface $request,
        string $requested_path
    ): ResponseInterface {
        $event = new RouteNotFoundEvent($request, $requested_path, __CLASS__);
        $this->event_bus->dispatch($event);

        // If a listener handled the event and provided a response, use it
        if ($event->hasResponse()) {
            return $event->getResponse();
        }

        // Otherwise, return a simple 404 response
        $response = $this->response_factory->createResponse(404);
        $response->getBody()->write('404 - Not Found');

        return $response;
    }

    /**
     * @throws \RuntimeException
     */
    public function getByName(string $route_name): Route
    {
        $this->assertRouteNameExist($route_name);

        return $this->routes_indexed_by_name->get($route_name);
    }

    protected function assertRouteNameExist(string $route_name): void
    {
        if (!$this->routes_indexed_by_name->offsetExists($route_name)) {
            throw new \RuntimeException("Route '{$route_name}' not found");
        }
    }

    public static function getUrlBase(): string
    {
        return $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function processRequest(
        ServerRequestInterface $request,
        Route $route,
        ?string $requestMethod = null
    ): ResponseInterface {
        if (!$this->assertMethodIsAllowedForRoute($request, $route, $requestMethod)) {
            return $this->createMethodNotAllowedResponse($route->getPath(), $route->getMethod());
        }
        if (!$this->checkRequiredParamsWereSend($route)) {
            return $this->response_factory->createResponse(400);
        }

        $response = $this->executeStack($route, $request);

        $event = new Event('core.onResponse', __CLASS__, ['response' => ['status' => $response->getStatusCode(), 'reason' => $response->getReasonPhrase()]]);
        $this->event_bus->dispatch($event);

        return $response;
    }

    protected function assertMethodIsAllowedForRoute(
        ServerRequestInterface $request,
        Route $route,
        ?string $requestMethod = null
    ): bool {
        $requestMethod = $requestMethod ?? $request->getMethod();
        return $requestMethod === $route->getMethod();
    }

    protected function checkRequiredParamsWereSend(Route $route): bool
    {
        if ($route->hasParameters()) {
            foreach ($route->getParameters() as $parameter) {
                if (
                    !empty($parameter['required'])
                    && (!isset($_GET[$parameter['name']])
                        && !isset($_POST[$parameter['name']]))
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    private function executeStack(
        Route $route,
        ServerRequestInterface $request
    ): ResponseInterface {
        $handler = $this->class_factory->build(
            $this->container,
            $route->getController()
        );
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \RuntimeException(sprintf(
                'Route controller "%s" must implement PSR-15 RequestHandlerInterface.',
                $route->getController()
            ));
        }

        if ($route->hasMiddlewares()) {
            $handler = new MiddlewarePipelineHandler($handler, $this->resolveMiddlewares($route));
        }

        return $handler->handle($request);
    }

    /** @return MiddlewareInterface[] */
    private function resolveMiddlewares(Route $route): array
    {
        $middlewares = [];
        foreach ($route->getMiddlewares() as $middlewareId) {
            $middleware = $this->container->get($middlewareId);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \RuntimeException(sprintf(
                    'Route middleware "%s" must resolve to PSR-15 MiddlewareInterface; got %s.',
                    $middlewareId,
                    is_object($middleware) ? get_class($middleware) : gettype($middleware)
                ));
            }
            $middlewares[] = $middleware;
        }

        return $middlewares;
    }
}
