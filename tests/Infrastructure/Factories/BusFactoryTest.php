<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Factories;

use Flexi\Contracts\Interfaces\ConfigurationRepositoryInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Infrastructure\Bus\CommandBus;
use Flexi\Infrastructure\Bus\EventBus;
use Flexi\Infrastructure\Bus\QueryBus;
use Flexi\Infrastructure\Factories\BusFactory;
use Flexi\Infrastructure\Classes\InMemoryCache;
use Flexi\Infrastructure\DependencyInjection\HandlersDefinitionParser;
use Flexi\Infrastructure\DependencyInjection\ListenersDefinitionParser;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class BusFactoryTest extends TestCase
{
    private $container;
    private $objectBuilder;
    private $configRepo;
    private $logger;
    private $handlersParser;
    private $listenersParser;
    private $configFilesProvider;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->objectBuilder = $this->createMock(ObjectBuilderInterface::class);
        $this->configRepo = $this->createMock(ConfigurationRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handlersParser = $this->createMock(HandlersDefinitionParser::class);
        $this->listenersParser = $this->createMock(ListenersDefinitionParser::class);
        $this->configFilesProvider = $this->createMock(ConfigurationFilesProviderInterface::class);

        // Configure default behavior for mocks
        // Configure container mock to return our mocked services
        $this->container
            ->method('get')
            ->willReturnCallback(function ($id) {
                switch ($id) {
                    case 'logger':
                        return $this->logger;
                    case ObjectBuilderInterface::class:
                        return $this->objectBuilder;
                    case ConfigurationRepositoryInterface::class:
                        return $this->configRepo;
                    case HandlersDefinitionParser::class:
                        return $this->handlersParser;
                    case ListenersDefinitionParser::class:
                        return $this->listenersParser;
                    case ConfigurationFilesProviderInterface::class:
                        return $this->configFilesProvider;
                    default:
                        throw new \Exception("Service not found: " . $id);
                }
            });
    }    /**
     * Helper method to create BusFactory instance with all mocks
     */
    private function createBusFactory(): BusFactory
    {
        return new BusFactory(
            $this->container,
            $this->handlersParser,
            $this->listenersParser,
            $this->configFilesProvider
        );
    }

    public function testConstruct(): void
    {
        $factory = $this->createBusFactory();
        $this->assertInstanceOf(BusFactory::class, $factory);
    }

    public function testGetInstanceCommandBus(): void
    {
        $factory = $this->createBusFactory();

        $bus = $factory->getInstance(CommandBus::class);

        $this->assertInstanceOf(CommandBus::class, $bus);
    }

    public function testGetInstanceQueryBus(): void
    {
        $factory = $this->createBusFactory();

        $bus = $factory->getInstance(QueryBus::class);

        $this->assertInstanceOf(QueryBus::class, $bus);
    }

    public function testGetInstanceEventBus(): void
    {
        $factory = $this->createBusFactory();

        $bus = $factory->getInstance(EventBus::class);

        $this->assertInstanceOf(EventBus::class, $bus);
    }

    public function testGetInstanceInvalidType(): void
    {
        $factory = $this->createBusFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid bus type');

        $factory->getInstance('InvalidBusType');
    }

    public function testDuplicateHandlerIdsAcrossFilesAreRejected(): void
    {
        $firstFile = '/modules/One/Config/commands.json';
        $secondFile = '/modules/Two/Config/commands.json';
        $this->configFilesProvider->method('getConfigurationFiles')->willReturn([$firstFile, $secondFile]);
        $this->handlersParser->method('parse')->willReturnOnConsecutiveCalls(
            [['id' => 'App\\Command', 'handler' => 'App\\CommandHandler', 'cli_alias' => null]],
            [['id' => 'App\\Command', 'handler' => 'Other\\CommandHandler', 'cli_alias' => null]]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Handler ID "App\\Command" is defined in both %s and %s.', $firstFile, $secondFile));
        $this->createBusFactory()->getInstance(CommandBus::class);
    }

    public function testNewBusBuildDoesNotRetainInactiveModuleHandlers(): void
    {
        $file = '/modules/Optional/Config/commands.json';
        $this->configFilesProvider->method('getConfigurationFiles')->willReturnOnConsecutiveCalls([$file], []);
        $this->handlersParser->method('parse')->with($file, $this->anything())->willReturn([
            ['id' => 'App\\OptionalCommand', 'handler' => 'App\\OptionalCommandHandler', 'cli_alias' => null],
        ]);

        $factory = $this->createBusFactory();
        $activeBus = $factory->getInstance(CommandBus::class);
        $inactiveBus = $factory->getInstance(CommandBus::class);

        $this->assertTrue($activeBus->hasHandler('App\\OptionalCommand'));
        $this->assertFalse($inactiveBus->hasHandler('App\\OptionalCommand'));
    }

    public function testHandlerAliasCannotCollideWithAnotherHandlerId(): void
    {
        $firstFile = '/modules/One/Config/commands.json';
        $secondFile = '/modules/Two/Config/commands.json';
        $this->configFilesProvider->method('getConfigurationFiles')->willReturn([$firstFile, $secondFile]);
        $this->handlersParser->method('parse')->willReturnOnConsecutiveCalls(
            [['id' => 'App\\FirstCommand', 'handler' => 'App\\FirstHandler', 'cli_alias' => 'shared']],
            [['id' => 'shared', 'handler' => 'App\\SecondHandler', 'cli_alias' => null]]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicts with alias declared in');
        $this->createBusFactory()->getInstance(CommandBus::class);
    }

    public function testGetInstanceWithoutLoggerService(): void
    {
        // Setup container to throw exception when getting logger
        $containerMock = $this->createMock(ContainerInterface::class);
        $containerMock->method('get')->willReturnCallback(function ($service) {
            if ($service === 'logger') {
                throw new \Exception('Logger service not available');
            }
            if ($service === ObjectBuilderInterface::class) {
                return $this->objectBuilder;
            }
            if ($service === ConfigurationRepositoryInterface::class) {
                return $this->configRepo;
            }
            throw new \Exception("Unknown service: $service");
        });

        $factory = new BusFactory(
            $containerMock,
            $this->handlersParser,
            $this->listenersParser,
            $this->configFilesProvider
        );

        // Should use NullLogger and still work
        $bus = $factory->getInstance(CommandBus::class);

        $this->assertInstanceOf(CommandBus::class, $bus);
    }

    public function testCreateCommandBus(): void
    {
        $bus = BusFactory::createCommandBus($this->container);

        $this->assertInstanceOf(CommandBus::class, $bus);
    }

    public function testCreateQueryBus(): void
    {
        $bus = BusFactory::createQueryBus($this->container);

        $this->assertInstanceOf(QueryBus::class, $bus);
    }

    public function testCreateEventBus(): void
    {
        $bus = BusFactory::createEventBus($this->container);

        $this->assertInstanceOf(EventBus::class, $bus);
    }
}
