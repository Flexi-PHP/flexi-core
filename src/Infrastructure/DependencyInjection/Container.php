<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Domain\Exceptions\ServiceNotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class Container implements ContainerInterface
{
    private const ERROR_SELF_REFERENCE = 'Cannot register self-referencing service: %s';
    private const ERROR_INVALID_DEFINITION = 'Service definition must be an object, an array, or a string class name.';

    private array $serviceDefinitions;
    private array $serviceInstances = [];
    private array $serviceSources = [];
    private array $selfReference = ['container', ContainerInterface::class];

    private ObjectBuilderInterface $factory;
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache, ObjectBuilderInterface $factory)
    {
        $this->cache = $cache;
        $this->factory = $factory;

        // Definitions and instances belong to this build and must not leak through a shared cache.
        $this->serviceDefinitions = [];

        $this->set('cache', $cache);
        $this->set('factory', $this->factory);
    }

    /**
     * Register a service definition.
     *
     * @param string              $id                The service ID
     * @param string|array|object $serviceDefinition The service definition
     */
    public function set(string $id, $serviceDefinition, ?string $source = null): void
    {
        if ($this->has($id)) {
            return;
        }

        $this->validateServiceDefinition($id, $serviceDefinition);
        if ($source !== null) {
            $this->serviceSources[$id] = $source;
        }

        if (is_object($serviceDefinition) && !is_callable($serviceDefinition)) {
            $this->serviceInstances[$id] = $serviceDefinition;

            return;
        }

        $this->serviceDefinitions[$id] = $serviceDefinition;
    }

    /**
     * Check if the container can provide a service with the given ID.
     */
    public function has(string $id): bool
    {
        return in_array($id, $this->selfReference, true)
            || isset($this->serviceDefinitions[$id])
            || isset($this->serviceInstances[$id]);
    }

    /**
     * Return the configuration file that contributed each registered service definition.
     *
     * @return string[] Service ID to configuration file path.
     */
    public function getServiceSources(): array
    {
        return $this->serviceSources;
    }

    /**
     * Get a service instance from the container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws \InvalidArgumentException
     */
    public function get(string $id): object
    {
        if (in_array($id, $this->selfReference, true)) {
            return $this;
        }
        if ('cache' === $id) {
            return $this->cache;
        }
        if ('factory' === $id || $id instanceof ObjectBuilderInterface) {
            return $this->factory;
        }

        if (isset($this->serviceInstances[$id])) {
            return $this->serviceInstances[$id];
        }

        try {
            $serviceInstance = $this->resolveServiceInstance($id);
        } catch (\Throwable $th) {
            $source = $this->serviceSources[$id] ?? null;
            $message = $source === null
                ? sprintf('Class %s does not exist', $id)
                : sprintf('Unable to resolve service "%s" from %s: %s', $id, $source, $th->getMessage());
            throw new ServiceNotFoundException($message, 0, $th);
        }

        $this->serviceInstances[$id] = $serviceInstance;

        return $serviceInstance;
    }

    /**
     * Resolves a service instance from its definition or builds it.
     *
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws \InvalidArgumentException
     */
    private function resolveServiceInstance(string $id): object
    {
        if (isset($this->serviceDefinitions[$id])) {
            $definition = $this->serviceDefinitions[$id];

            if (is_array($definition)) {
                return $this->factory->buildFromDefinition($this, $definition);
            }

            if (is_string($definition)) {
                return $this->resolveAlias($definition);
            }
        }

        return $this->factory->build($this, $id);
    }

    /**
     * Resolves an alias to another service.
     */
    private function resolveAlias(string $alias): object
    {
        if ($this->has($alias)) {
            return $this->get($alias);
        }

        throw new \InvalidArgumentException(sprintf('Service alias "%s" cannot be resolved.', $alias));
    }

    /**
     * Validates a service definition.
     */
    private function validateServiceDefinition(string $id, $serviceDefinition): void
    {
        if (in_array($id, $this->selfReference, true)) {
            throw new \InvalidArgumentException(sprintf(self::ERROR_SELF_REFERENCE, $id));
        }

        if (!is_object($serviceDefinition) && !is_array($serviceDefinition) && !is_string($serviceDefinition)) {
            throw new \InvalidArgumentException(self::ERROR_INVALID_DEFINITION);
        }
    }
}
