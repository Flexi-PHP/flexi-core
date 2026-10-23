<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Http;

class Route
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'TRACE', 'CONNECT'];
    private string $path;
    private string $controller;
    /**
     * @var string GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD|TRACE|CONNECT
     */
    private string $method;
    private array $parameters;
    private string $name;
    private array $middlewares;
    private ?string $overrides;

    public function __construct(
        string $name,
        string $path,
        string $controller,
        string $method = 'GET',
        array $parameters = [],
        array $middlewares = [],
        ?string $overrides = null
    ) {
        $this->validateMethod($method);
        $this->name = $name;
        $this->path = $path;
        $this->controller = $controller;
        $this->method = $method;
        $this->parameters = $parameters;
        $this->middlewares = $middlewares;
        $this->overrides = $overrides;
    }

    public function getController(): string
    {
        return $this->controller;
    }

    /**
     * @return string GET|POST|PUT|PATCH|DELETE|OPTIONS|HEAD|TRACE|CONNECT
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getAbsoluteUrl(string $base_url): string
    {
        $this->assertBaseURL($base_url);

        return $base_url.$this->path;
    }

    private function assertBaseURL(string $base_url): void
    {
        if (
            empty($base_url)
            || false === strpos($base_url, $_SERVER['REQUEST_SCHEME'])
        ) {
            throw new \InvalidArgumentException('Base URL is not valid');
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasParameters(): bool
    {
        return !empty($this->parameters);
    }

    private function validateMethod(string $method): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException("Invalid method: $method");
        }
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function hasMiddlewares(): bool
    {
        return !empty($this->middlewares);
    }

    public function getOverrides(): ?string
    {
        return $this->overrides;
    }
}
