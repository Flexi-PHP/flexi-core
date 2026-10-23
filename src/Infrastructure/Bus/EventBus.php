<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Bus;

use Flexi\Contracts\Classes\Traits\GlobFileReader;
use Flexi\Contracts\Classes\Traits\JsonFileReader;
use Flexi\Contracts\Interfaces\ConfigurationRepositoryInterface;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\EventInterface;
use Flexi\Contracts\Interfaces\EventListenerInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Domain\Commands\NotFoundCommand;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

class EventBus implements EventBusInterface
{

    private array $events = [];
    private ContainerInterface $container;
    private ObjectBuilderInterface $class_factory;
    private LoggerInterface $logger;
    private ConfigurationRepositoryInterface $configuration;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container,
    ObjectBuilderInterface $class_factory,
    LoggerInterface $logger,
    ConfigurationRepositoryInterface $configuration_repository
    )
    {
        $this->container = $container;
        $this->class_factory = $class_factory;
        $this->logger = $logger;
        $this->configuration = $configuration_repository;
    }

    public function register(
        string $identifier,
        string $handler,
        ?string $cli_alias = null
    ): void {
        $this->events[$identifier][] = $handler;
    }

    /**
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function execute(DTOInterface $dto): void
    {
        if ($dto instanceof EventInterface) {
            $this->dispatch($dto);
        }
    }

    /**
     * Dispatches an event to all relevant listeners.
     *
     * @param object $event the event object to dispatch
     *
     * @return object the event after processing by listeners
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function dispatch(object $event): object
    {
        $identifier = $event instanceof EventInterface ? $event->getName() : get_class($event);

        $listeners = array_merge($this->getListeners($identifier), $this->getListeners('*'));

        if (empty($listeners)) {
            return $event;
        }

        if ($this->asyncMode()) {
            $this->dispatchAsync($listeners, $event);
        } else {
            $this->notifyListeners($listeners, $event);
        }

        return $event;
    }

    public function hasHandler(string $identifier): bool
    {
        return isset($this->events[$identifier]);
    }

    /**
     * @throws \JsonException
     */
    public function getHandler(string $identifier): string
    {
        return json_encode($this->getListeners($identifier), JSON_THROW_ON_ERROR);
    }

    public function getListeners(string $event): array
    {
        return $this->events[$event] ?? [];
    }

    public function getDtoClassFromAlias(string $id): string
    {
        return NotFoundCommand::class;
    }

    /**
     * @throws \ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function notifyListeners(array $listeners, object $event): void
    {
        foreach ($listeners as $listener) {
            $this->handleListener($listener, $event);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     * @throws NotFoundExceptionInterface
     */
    private function handleListener(string $listener, object $event): void
    {
        if (!($event instanceof EventInterface) || $event->isPropagationStopped()) {
            return;
        }

        $listener_obj = $this->resolveListener($listener);
        if (!$listener_obj instanceof EventListenerInterface) {
            throw new \RuntimeException(sprintf(
                'Event listener "%s" must implement %s.',
                $listener,
                EventListenerInterface::class
            ));
        }
        $listener_obj->handle($event);
    }

    private function resolveListener(string $listener): object
    {
        return $this->container->has($listener)
            ? $this->container->get($listener)
            : $this->class_factory->build($this->container, $listener);
    }

    private function closeBuffers(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();  // Flush all data to the client
        }

        // Close the standard file descriptors to prevent writing to the console
        try {
            if (defined('STDIN') && is_resource(STDIN)) {
                fclose(STDIN);
            }

            if (defined('STDOUT') && is_resource(STDOUT)) {
                fclose(STDOUT);
            }

            if (defined('STDERR') && is_resource(STDERR)) {
                fclose(STDERR);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Error closing file descriptors: '.$e->getMessage(), [__CLASS__]);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    private function dispatchAsync(array $listeners, object $event): void
    {
        $pid = pcntl_fork();

        if (-1 === $pid) {
            $this->logger->error('Could not fork process');
            throw new \RuntimeException('Could not fork process');
        }

        if (0 === $pid) {
            $this->notifyListeners($listeners, $event);

            $this->closeBuffers(); // Close the standard file descriptors to prevent blank output on parent process

            exit(0);
        }
        pcntl_waitpid($pid, $status, WNOHANG);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function asyncMode(): bool
    {
        return PHP_SAPI === 'cli' && ($this->configuration->has('dispatch_mode') && (int) $this->configuration->get('dispatch_mode'));
    }

    public function getHandlersDefinition(bool $with_aliases = false): array
    {
        // EventBus doesn't have traditional handlers like Command/QueryBus
        // Return event listeners mapping
        return $this->events;
    }
}
