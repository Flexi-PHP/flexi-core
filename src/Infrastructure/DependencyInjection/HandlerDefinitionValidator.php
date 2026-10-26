<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\DependencyInjection;

use Flexi\Contracts\Interfaces\EventListenerInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/** Performs constructor-free class and contract checks for configured handlers. */
class HandlerDefinitionValidator
{
    private const CONTRACTS_BY_CONFIGURATION = [
        'routes' => ['controller' => RequestHandlerInterface::class, 'middlewares' => MiddlewareInterface::class],
        'listeners' => ['handler' => EventListenerInterface::class],
        'commands' => ['handler' => HandlerInterface::class],
        'queries' => ['handler' => HandlerInterface::class],
    ];

    /**
     * @param array<string, string[]> $configurationFiles
     * @param array<string, mixed> $serviceDefinitions
     */
    public function validate(array $configurationFiles, array $serviceDefinitions): void
    {
        foreach (self::CONTRACTS_BY_CONFIGURATION as $configurationType => $fields) {
            foreach ($configurationFiles[$configurationType] ?? [] as $file) {
                $document = json_decode((string) file_get_contents($file), true);
                $definitions = $this->getDefinitions($configurationType, $document);

                foreach ($definitions as $definition) {
                    foreach ($fields as $field => $contract) {
                        $values = $field === 'middlewares'
                            ? ($definition[$field] ?? [])
                            : (isset($definition[$field]) ? [$definition[$field]] : []);
                        foreach ($values as $identifier) {
                            $this->assertCompatible($identifier, $contract, $serviceDefinitions, $file);
                        }
                    }
                }
            }
        }
    }

    private function getDefinitions(string $type, array $document): array
    {
        if ($type === 'listeners') {
            $definitions = [];
            foreach ($document as $event) {
                foreach ($event['listeners'] as $listener) {
                    $definitions[] = ['handler' => $listener];
                }
            }

            return $definitions;
        }

        if ($type === 'routes') {
            return $document['routes'];
        }

        return $document['handlers'];
    }

    private function assertCompatible(
        string $identifier,
        string $contract,
        array $serviceDefinitions,
        string $file
    ): void {
        $type = $this->resolveType($identifier, $serviceDefinitions, []);
        if ($type === null) {
            // Untyped factory results remain covered by the runtime instanceof guard.
            if ($this->resolvesThroughUntypedFactory($identifier, $serviceDefinitions, [])) {
                return;
            }
            throw new \RuntimeException(sprintf(
                'Configured handler "%s" in %s cannot resolve to a loadable class.',
                $identifier,
                $file
            ));
        }

        if (!class_exists($type) && !interface_exists($type)) {
            throw new \RuntimeException(sprintf(
                'Configured handler "%s" in %s cannot resolve to a loadable class.',
                $identifier,
                $file
            ));
        }

        if (!is_a($type, $contract, true)) {
            throw new \RuntimeException(sprintf(
                'Configured handler "%s" in %s must implement %s; resolved type is %s.',
                $identifier,
                $file,
                $contract,
                $type
            ));
        }

        $factoryResult = $this->resolvesThroughFactory($identifier, $serviceDefinitions, []);
        if (!$factoryResult && (interface_exists($type) || !(new ReflectionClass($type))->isInstantiable())) {
            throw new \RuntimeException(sprintf(
                'Configured handler class "%s" in %s is not instantiable.',
                $identifier,
                $file
            ));
        }
    }

    private function resolveType(string $identifier, array $serviceDefinitions, array $visited): ?string
    {
        if (isset($visited[$identifier])) {
            throw new \RuntimeException(sprintf('Circular service alias detected while validating handler "%s".', $identifier));
        }
        $visited[$identifier] = true;

        if (!array_key_exists($identifier, $serviceDefinitions)) {
            return class_exists($identifier) || interface_exists($identifier) ? $identifier : null;
        }

        $definition = $serviceDefinitions[$identifier];
        if (is_string($definition)) {
            return $this->resolveType($definition, $serviceDefinitions, $visited);
        }
        if (!is_array($definition)) {
            return is_object($definition) ? get_class($definition) : null;
        }
        if (isset($definition['class']['name'])) {
            return $definition['class']['name'];
        }
        if (isset($definition['factory']['class'], $definition['factory']['method'])) {
            return $this->getFactoryReturnType($definition['factory']['class'], $definition['factory']['method']);
        }

        return null;
    }

    private function getFactoryReturnType(string $class, string $method): ?string
    {
        if (!class_exists($class) || !(new ReflectionClass($class))->hasMethod($method)) {
            throw new \RuntimeException(sprintf('Configured service factory "%s::%s" cannot be resolved.', $class, $method));
        }

        $factoryMethod = new ReflectionMethod($class, $method);
        if (!$factoryMethod->isPublic() || !$factoryMethod->isStatic()) {
            throw new \RuntimeException(sprintf('Configured service factory "%s::%s" must be public and static.', $class, $method));
        }
        $returnType = $factoryMethod->getReturnType();
        if (!$returnType instanceof ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        return $returnType->getName();
    }

    private function resolvesThroughUntypedFactory(string $identifier, array $serviceDefinitions, array $visited): bool
    {
        if (isset($visited[$identifier]) || !array_key_exists($identifier, $serviceDefinitions)) {
            return false;
        }
        $visited[$identifier] = true;
        $definition = $serviceDefinitions[$identifier];
        if (is_string($definition)) {
            return $this->resolvesThroughUntypedFactory($definition, $serviceDefinitions, $visited);
        }
        if (!is_array($definition) || !isset($definition['factory']['class'], $definition['factory']['method'])) {
            return false;
        }

        return $this->getFactoryReturnType($definition['factory']['class'], $definition['factory']['method']) === null;
    }

    private function resolvesThroughFactory(string $identifier, array $serviceDefinitions, array $visited): bool
    {
        if (isset($visited[$identifier]) || !array_key_exists($identifier, $serviceDefinitions)) {
            return false;
        }
        $visited[$identifier] = true;
        $definition = $serviceDefinitions[$identifier];
        if (is_string($definition)) {
            return $this->resolvesThroughFactory($definition, $serviceDefinitions, $visited);
        }

        return is_array($definition) && isset($definition['factory']);
    }
}
