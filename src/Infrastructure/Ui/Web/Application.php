<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Ui\Web;

use Flexi\Contracts\Interfaces\ConfigurationInterface;
use Flexi\Infrastructure\Factories\RouterFactory;
use Flexi\Infrastructure\ErrorHandling\ErrorHandlerCall;
use Flexi\Infrastructure\Http\Router;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;
use Symfony\Component\ErrorHandler\Debug;

class Application
{
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \ErrorException
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws InvalidArgumentException
     */
    public function run(): void
    {
        $config = $this->container->get(ConfigurationInterface::class);
        $debugMode = 'true' === $config->get('DEBUG_MODE');
        if ($debugMode) {
            Debug::enable();
        }
        echo ErrorHandlerCall::run(function () use ($debugMode) {
            return $this->handle($debugMode);
        });
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws InvalidArgumentException
     */
    private function handle(bool $debugMode): StreamInterface
    {
        try {
            /** @var Router $router */
            $router = $this->container->get(RouterFactory::class);
            $response = $router->dispatch(ServerRequest::fromGlobals());
        } catch (Throwable $exception) {
            $responseFactory = $this->container->get(\Psr\Http\Message\ResponseFactoryInterface::class);
            $response = $responseFactory->createResponse(500);
            $response->getBody()->write($debugMode ? $exception->getMessage() : '500 - Internal Server Error');
            $response = $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->sendResponse($response);
    }

    private function sendResponse(ResponseInterface $response): StreamInterface
    {
        header(
            "HTTP/1.1 {$response->getStatusCode()} {$response->getReasonPhrase()}"
        );
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("$name: $value", false);
            }
        }

        return $response->getBody();
    }
}
