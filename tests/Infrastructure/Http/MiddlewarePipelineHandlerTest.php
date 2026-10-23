<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Http;

use Flexi\Infrastructure\Http\MiddlewarePipelineHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipelineHandlerTest extends TestCase
{
    public function testMiddlewareRunsInDeclaredOrderAroundAnyPsrRequestHandler(): void
    {
        $events = (object) ['items' => []];
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->with($request)->willReturnCallback(
            static function () use ($events, $response) {
                $events->items[] = 'handler';
                return $response;
            }
        );

        $pipeline = new MiddlewarePipelineHandler($handler, [
            $this->createMiddleware($events, 'first'),
            $this->createMiddleware($events, 'second'),
        ]);

        $this->assertSame($response, $pipeline->handle($request));
        $this->assertSame([
            'first:before',
            'second:before',
            'handler',
            'second:after',
            'first:after',
        ], $events->items);
    }

    public function testMiddlewareCanShortCircuitThePipeline(): void
    {
        $events = (object) ['items' => []];
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $pipeline = new MiddlewarePipelineHandler($handler, [
            $this->createMiddleware($events, 'stop', $response),
        ]);

        $this->assertSame($response, $pipeline->handle($request));
        $this->assertSame(['stop:before'], $events->items);
    }

    private function createMiddleware(object $events, string $name, ?ResponseInterface $shortCircuit = null): MiddlewareInterface
    {
        return new class($events, $name, $shortCircuit) implements MiddlewareInterface {
            private object $events;
            private string $name;
            private ?ResponseInterface $shortCircuit;

            public function __construct(object $events, string $name, ?ResponseInterface $shortCircuit)
            {
                $this->events = $events;
                $this->name = $name;
                $this->shortCircuit = $shortCircuit;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->events->items[] = $this->name.':before';
                if ($this->shortCircuit !== null) {
                    return $this->shortCircuit;
                }

                $response = $handler->handle($request);
                $this->events->items[] = $this->name.':after';

                return $response;
            }
        };
    }
}
