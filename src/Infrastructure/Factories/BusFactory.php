<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Factories;

use Flexi\Contracts\Interfaces\BusInterface;
use Flexi\Contracts\Interfaces\ConfigurationRepositoryInterface;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Infrastructure\Bus\CommandBus;
use Flexi\Infrastructure\Bus\EventBus;
use Flexi\Infrastructure\Bus\QueryBus;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Infrastructure\DependencyInjection\HandlersDefinitionParser;
use Flexi\Infrastructure\DependencyInjection\ListenersDefinitionParser;
use Flexi\Domain\ValueObjects\ConfigurationType;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;

class BusFactory
{
    private ContainerInterface $container;
    private ConfigurationFilesProviderInterface $config_files_provider;
    private HandlersDefinitionParser $handlers_parser;
    private ListenersDefinitionParser $listeners_parser;

    public function __construct(
        ContainerInterface $container,
        HandlersDefinitionParser $handlers_parser,
        ListenersDefinitionParser $listeners_parser,
        ConfigurationFilesProviderInterface $config_files_provider
    ) {
        $this->container = $container;
        $this->handlers_parser = $handlers_parser;
        $this->listeners_parser = $listeners_parser;
        $this->config_files_provider = $config_files_provider;
    }

    /**
     * Create bus instance with automatic handlers/listeners discovery
     *
     * @param string $type Bus type (CommandBus::class, QueryBus::class, EventBus::class)
     * @return BusInterface|EventBusInterface
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \JsonException
     * @throws \ReflectionException
     */
    public function getInstance(
        string $type
    ): BusInterface {
        // Use NullLogger if logger service is not available (e.g., Logging module not installed)
        try {
            $logger = $this->container->get('logger');
        } catch (\Exception $e) {
            $logger = new NullLogger();
        }

        $class_factory = $this->container->get(ObjectBuilderInterface::class);
        $configuration = $this->container->get(ConfigurationRepositoryInterface::class);
        $event_bus = new EventBus($this->container, $class_factory, $logger, $configuration);

        switch ($type) {
            case CommandBus::class:
                $bus = new CommandBus($this->container, $event_bus, $class_factory);
                $configType = ConfigurationType::commands();
                break;
            case QueryBus::class:
                $bus = new QueryBus($this->container, $event_bus, $class_factory);
                $configType = ConfigurationType::queries();
                break;
            case EventBus::class:
                $bus = $event_bus;
                $configType = ConfigurationType::listeners();
                break;
            default:
                throw new \InvalidArgumentException('Invalid bus type');
        }

        // Load handlers from config provider
        if ($type === EventBus::class) {
            $this->loadListenersFromProvider($bus);
        } else{
            $this->loadHandlersFromProvider($bus, $configType);
        }

        return $bus;
    }

    /**
     * Load listeners from configuration provider for EventBus
     */
    private function loadListenersFromProvider(EventBus $eventBus): void
    {
        $listenerFiles = $this->config_files_provider->getConfigurationFiles(ConfigurationType::listeners());

        foreach ($listenerFiles as $file) {
            $listeners = $this->listeners_parser->parse($file);
            foreach ($listeners as $listener) {
                $eventBus->register($listener['event'], $listener['handler']);
            }
        }
    }

    /**
     * Load handlers from configuration provider for Command/Query buses
     */
    private function loadHandlersFromProvider(BusInterface $bus, ConfigurationType $configType): void
    {
        $handlerFiles = $this->config_files_provider->getConfigurationFiles($configType);

        $registeredIds = [];
        $registeredAliases = [];
        foreach ($handlerFiles as $file) {
            $handlers = $this->handlers_parser->parse($file, $configType);
            foreach ($handlers as $handler) {
                $id = $handler['id'];
                if (isset($registeredIds[$id])) {
                    throw new \RuntimeException(sprintf(
                        'Handler ID "%s" is defined in both %s and %s.',
                        $id,
                        $registeredIds[$id],
                        $file
                    ));
                }
                if (isset($registeredAliases[$id])) {
                    throw new \RuntimeException(sprintf(
                        'Handler ID "%s" in %s conflicts with alias declared in %s.',
                        $id,
                        $file,
                        $registeredAliases[$id]
                    ));
                }
                $registeredIds[$id] = $file;

                $alias = $handler['cli_alias'] ?? null;
                if ($alias !== null) {
                    if (isset($registeredAliases[$alias])) {
                        throw new \RuntimeException(sprintf(
                            'Handler alias "%s" is defined in both %s and %s.',
                            $alias,
                            $registeredAliases[$alias],
                            $file
                        ));
                    }
                    if (isset($registeredIds[$alias])) {
                        throw new \RuntimeException(sprintf(
                            'Handler alias "%s" in %s conflicts with handler ID declared in %s.',
                            $alias,
                            $file,
                            $registeredIds[$alias]
                        ));
                    }
                    $registeredAliases[$alias] = $file;
                }

                $bus->register($handler['id'], $handler['handler'], $handler['cli_alias'] ?? null);
            }
        }
    }

    /** @return CommandBus */
    public static function createCommandBus(
        ContainerInterface $container
    ): BusInterface {
        $handlersParser = $container->get(HandlersDefinitionParser::class);
        $listenersParser = $container->get(ListenersDefinitionParser::class);
        $configFilesProvider = $container->get(ConfigurationFilesProviderInterface::class);

        $factory = new self($container, $handlersParser, $listenersParser, $configFilesProvider);

        return $factory->getInstance(CommandBus::class);
    }

    /** @return QueryBus */
    public static function createQueryBus(
        ContainerInterface $container
    ): BusInterface {
        $handlersParser = $container->get(HandlersDefinitionParser::class);
        $listenersParser = $container->get(ListenersDefinitionParser::class);
        $configFilesProvider = $container->get(ConfigurationFilesProviderInterface::class);

        $factory = new self($container, $handlersParser, $listenersParser, $configFilesProvider);

        return $factory->getInstance(QueryBus::class);
    }

    /** @return EventBus */
    public static function createEventBus(
        ContainerInterface $container
    ): EventBusInterface {
        $handlersParser = $container->get(HandlersDefinitionParser::class);
        $listenersParser = $container->get(ListenersDefinitionParser::class);
        $configFilesProvider = $container->get(ConfigurationFilesProviderInterface::class);

        $factory = new self($container, $handlersParser, $listenersParser, $configFilesProvider);

        return $factory->getInstance(EventBus::class);
    }
}
