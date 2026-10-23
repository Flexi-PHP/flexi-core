<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Bus;

use Flexi\Contracts\Interfaces\BusInterface;
use Flexi\Contracts\Interfaces\DTOInterface;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\HandlerInterface;
use Flexi\Contracts\Interfaces\MessageInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Domain\Commands\NotFoundCommand;
use Flexi\Domain\Events\Event;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class CommandBus implements BusInterface
{

    private array $commands = [];
    private array $aliases = [];
    private ContainerInterface $container;
    private EventBusInterface $event_bus;
    private ObjectBuilderInterface $class_factory;

    /**
     * @param EventBus $event_bus
     */
    public function __construct(ContainerInterface $container, EventBusInterface $event_bus, ObjectBuilderInterface $class_factory)
    {
        $this->container = $container;
        $this->event_bus = $event_bus;
        $this->class_factory = $class_factory;
    }

    public function register(
        string $identifier,
        string $handler,
        ?string $cli_alias = null
    ): void {
        if (isset($this->commands[$identifier]) || isset($this->aliases[$identifier])) {
            throw new \InvalidArgumentException(sprintf('Handler ID "%s" is already registered.', $identifier));
        }
        if ($cli_alias !== null && ($cli_alias === $identifier || isset($this->commands[$cli_alias]) || isset($this->aliases[$cli_alias]))) {
            throw new \InvalidArgumentException(sprintf('Handler alias "%s" is already registered or conflicts with a handler ID.', $cli_alias));
        }
        $this->commands[$identifier] = $handler;
        if ($cli_alias !== null) {
            $this->registerCliAlias($cli_alias, $handler);
        }
    }

    public function registerCliAlias(string $alias, string $handler): void
    {
        if (isset($this->aliases[$alias]) || isset($this->commands[$alias])) {
            throw new \InvalidArgumentException(sprintf('Handler alias "%s" is already registered or conflicts with a handler ID.', $alias));
        }
        $this->aliases[$alias] = $handler;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     */
    public function execute(DTOInterface $dto): MessageInterface
    {
        $identifier = get_class($dto);

        $handler = $this->getHandler($identifier);

        /** @var HandlerInterface $handler_obj */
        $handler_obj = $this->class_factory->build($this->container, $handler);

        $event_before = new Event('core.command.before_execute', __CLASS__, [
            'command' => $identifier,
        ]);
        $this->event_bus->dispatch($event_before);
        $response = $handler_obj->handle($dto);
        $event_after = new Event('core.command.after_execute', __CLASS__, [
            'command' => $identifier,
        ]);
        $this->event_bus->dispatch($event_after);

        return $response;
    }

    public function getHandler(string $identifier): string
    {
        $this->assertHandlerExist($identifier);

        return $this->aliases[$identifier] ?? $this->commands[$identifier];
    }

    private function assertHandlerExist(string $commandClass): void
    {
        if (!$this->hasHandler($commandClass)) {
            throw new \RuntimeException("Not handler found for $commandClass command");
        }
    }

    public function hasHandler(string $identifier): bool
    {
       return isset($this->commands[$identifier]) || isset($this->aliases[$identifier]);
    }

    public function getHandlersDefinition(bool $with_aliases = false): array
    {
        $list = $this->commands;
        if ($with_aliases) {
            $list = array_merge($list, $this->aliases);
        }

        return $list;
    }

    public function getDtoClassFromAlias(string $id): string
    {
        $handler = $this->aliases[$id];
        $dto = array_search($handler, $this->commands, true);

        return $dto ?: NotFoundCommand::class;
    }
}
