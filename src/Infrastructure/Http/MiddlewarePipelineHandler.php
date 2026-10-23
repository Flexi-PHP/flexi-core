<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipelineHandler implements RequestHandlerInterface
{
    private RequestHandlerInterface $handler;

    /**
     * @param MiddlewareInterface[] $middlewares In execution order, outermost first.
     */
    public function __construct(RequestHandlerInterface $handler, array $middlewares)
    {
        foreach (array_reverse($middlewares) as $middleware) {
            if (!$middleware instanceof MiddlewareInterface) {
                throw new \InvalidArgumentException('Every route middleware must implement PSR-15 MiddlewareInterface.');
            }

            $handler = new MiddlewarePipelineStep($middleware, $handler);
        }

        $this->handler = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handler->handle($request);
    }
}

/** @internal */
final class MiddlewarePipelineStep implements RequestHandlerInterface
{
    private MiddlewareInterface $middleware;
    private RequestHandlerInterface $handler;

    public function __construct(MiddlewareInterface $middleware, RequestHandlerInterface $handler)
    {
        $this->middleware = $middleware;
        $this->handler = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->handler);
    }
}
