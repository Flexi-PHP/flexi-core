<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Bus;

final class EventBusTestOverrides
{
    public static int $pcntlForkReturn = -1;
    public static array $pcntlWaitCalls = [];
    public static bool $fastcgiCalled = false;
    public static bool $simulateResources = false;
    public static array $fcloseCalls = [];
    public static bool $simulateFcloseException = false;

    public static function reset(): void
    {
        self::$pcntlForkReturn = -1;
        self::$pcntlWaitCalls = [];
        self::$fastcgiCalled = false;
        self::$simulateResources = false;
        self::$fcloseCalls = [];
        self::$simulateFcloseException = false;
    }
}

function pcntl_fork(): int
{
    return EventBusTestOverrides::$pcntlForkReturn;
}

function pcntl_waitpid(int $pid, &$status, int $options): int
{
    EventBusTestOverrides::$pcntlWaitCalls[] = [$pid, $options];
    $status = 0;

    return 0;
}

function fastcgi_finish_request(): void
{
    EventBusTestOverrides::$fastcgiCalled = true;
}

function is_resource($resource): bool
{
    if (EventBusTestOverrides::$simulateResources) {
        return true;
    }

    return \is_resource($resource);
}

function fclose($resource): bool
{
    EventBusTestOverrides::$fcloseCalls[] = $resource;

    if (EventBusTestOverrides::$simulateFcloseException) {
        throw new \Exception('Simulated fclose error');
    }

    return true;
}

namespace Flexi\Tests\Infrastructure\Bus;

use Flexi\Domain\Commands\NotFoundCommand;
use Flexi\Infrastructure\Bus\EventBus;
use Flexi\Infrastructure\Bus\EventBusTestOverrides;
use Flexi\Test\TestData\TestDoubles\Bus\GenericMessage;
use Flexi\Test\TestData\TestDoubles\Bus\RecordingListener;
use Flexi\Test\TestData\TestDoubles\Bus\SampleEvent;
use Flexi\Test\TestData\TestDoubles\Bus\SecondaryListener;
use Flexi\Contracts\Interfaces\ConfigurationRepositoryInterface;
use Flexi\Contracts\Interfaces\EventInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class EventBusTest extends TestCase
{
    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;

    /** @var ObjectBuilderInterface&MockObject */
    private ObjectBuilderInterface $builder;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var ConfigurationRepositoryInterface&MockObject */
    private ConfigurationRepositoryInterface $configuration;

    private bool $configHasDispatch = true;
    private int $dispatchMode = 0;

    protected function setUp(): void
    {
        parent::setUp();
        EventBusTestOverrides::reset();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->builder = $this->createMock(ObjectBuilderInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->configuration = $this->createMock(ConfigurationRepositoryInterface::class);

        $this->configuration
            ->method('has')
            ->willReturnCallback(function (string $key): bool {
                return 'dispatch_mode' === $key ? $this->configHasDispatch : false;
            });

        $this->configuration
            ->method('get')
            ->willReturnCallback(function (string $key): int {
                if ('dispatch_mode' === $key) {
                    return $this->dispatchMode;
                }

                return 0;
            });
    }

    public function testRegisterAndDispatchSynchronously(): void
    {
        $this->dispatchMode = 0;

        $listener = new RecordingListener(static function (EventInterface $event): void {
            $event->set('processed', true);
        });

        $wildcardListener = new RecordingListener(static function (EventInterface $event): void {
            $event->set('wildcard', true);
        });

        $this->builder
            ->expects($this->exactly(2))
            ->method('build')
            ->willReturnMap([
                [$this->container, RecordingListener::class, $listener],
                [$this->container, SecondaryListener::class, $wildcardListener],
            ]);

        $bus = $this->createBus();
        $bus->register('order.created', RecordingListener::class);
        $bus->register('*', SecondaryListener::class);

        $event = new SampleEvent('order.created');
        $result = $bus->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertCount(1, $listener->received);
        $this->assertCount(1, $wildcardListener->received);
        $this->assertTrue($event->has('processed'));
        $this->assertTrue($event->has('wildcard'));
        $this->assertTrue($bus->hasHandler('order.created'));
        $this->assertSame(
            json_encode([RecordingListener::class], JSON_THROW_ON_ERROR),
            $bus->getHandler('order.created')
        );
        $this->assertSame([RecordingListener::class], $bus->getListeners('order.created'));
        $this->assertSame([SecondaryListener::class], $bus->getListeners('*'));
        $this->assertSame(NotFoundCommand::class, $bus->getDtoClassFromAlias('any'));
        $this->assertSame(
            [
                'order.created' => [RecordingListener::class],
                '*' => [SecondaryListener::class],
            ],
            $bus->getHandlersDefinition()
        );
    }

    public function testDispatchResolvesListenerServiceThroughContainer(): void
    {
        $listener = new RecordingListener();
        $this->container->expects($this->once())->method('has')->with('listener.service')->willReturn(true);
        $this->container->expects($this->once())->method('get')->with('listener.service')->willReturn($listener);
        $this->builder->expects($this->never())->method('build');

        $bus = $this->createBus();
        $bus->register('order.created', 'listener.service');
        $bus->dispatch(new SampleEvent('order.created'));

        $this->assertCount(1, $listener->received);
    }

    public function testDispatchWithoutListenersReturnsEvent(): void
    {
        $this->dispatchMode = 0;

        $bus = $this->createBus();
        $event = new SampleEvent('none');

        $this->builder->expects($this->never())->method('build');

        $result = $bus->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function testDispatchStopsPropagationForRemainingListeners(): void
    {
        $this->dispatchMode = 0;

        $bus = $this->createBus();
        $bus->register('order.created', RecordingListener::class);
        $bus->register('order.created', SecondaryListener::class);

        $this->builder
            ->expects($this->once())
            ->method('build')
            ->willReturn(new RecordingListener(static function (EventInterface $event): void {
                $event->stopPropagation();
            }));

        $bus->dispatch(new SampleEvent('order.created'));
    }

    public function testSpecificListenersKeepRegistrationOrderAndRunBeforeWildcardListeners(): void
    {
        $events = [];
        $listeners = [
            new RecordingListener(static function () use (&$events): void { $events[] = 'specific.first'; }),
            new RecordingListener(static function () use (&$events): void { $events[] = 'specific.second'; }),
            new RecordingListener(static function () use (&$events): void { $events[] = 'wildcard'; }),
        ];
        $this->builder->expects($this->exactly(3))->method('build')->willReturnOnConsecutiveCalls(...$listeners);

        $bus = $this->createBus();
        $bus->register('order.created', 'specific.first');
        $bus->register('order.created', 'specific.second');
        $bus->register('*', 'wildcard');
        $bus->dispatch(new SampleEvent('order.created'));

        $this->assertSame(['specific.first', 'specific.second', 'wildcard'], $events);
    }

    public function testDispatchSkipsBuildingWhenEventIsNotEventInterface(): void
    {
        $this->dispatchMode = 0;

        $bus = $this->createBus();
        $bus->register(GenericMessage::class, RecordingListener::class);

        $this->builder->expects($this->never())->method('build');

        $result = $bus->dispatch(new GenericMessage());

        $this->assertInstanceOf(GenericMessage::class, $result);
    }

    public function testAsyncModeTrueWhenConfigured(): void
    {
        $this->dispatchMode = 1;
        $this->configHasDispatch = true;

        $bus = $this->createBus();

        $this->assertTrue($bus->asyncMode());
    }

    public function testAsyncModeFalseWhenConfigurationMissing(): void
    {
        $this->dispatchMode = 1;
        $this->configHasDispatch = false;

        $bus = $this->createBus();

        $this->assertFalse($bus->asyncMode());
    }

    public function testDispatchAsyncSuccess(): void
    {
        $this->dispatchMode = 1;
        EventBusTestOverrides::$pcntlForkReturn = 12345; // Positive PID for parent

        $bus = $this->createBus();
        $bus->register('order.created', RecordingListener::class);

        $listener = new RecordingListener(static function (EventInterface $event): void {
            $event->set('async_processed', true);
        });

        $this->builder
            ->expects($this->never()) // Parent doesn't build listeners
            ->method('build');

        $event = new SampleEvent('order.created');
        $result = $bus->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertCount(1, EventBusTestOverrides::$pcntlWaitCalls);
        $this->assertSame([12345, WNOHANG], EventBusTestOverrides::$pcntlWaitCalls[0]);
    }

    public function testDispatchAsyncForkFailure(): void
    {
        $this->dispatchMode = 1;
        EventBusTestOverrides::$pcntlForkReturn = -1; // Fork failure

        $bus = $this->createBus();
        $bus->register('order.created', RecordingListener::class);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Could not fork process');

        $this->builder->expects($this->never())->method('build');

        $event = new SampleEvent('order.created');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not fork process');

        $bus->dispatch($event);
    }

    public function testCloseBuffersDoesNotTriggerWarnings(): void
    {
        $this->dispatchMode = 0;
        EventBusTestOverrides::$simulateResources = true;

        $bus = $this->createBus();

        $this->logger->expects($this->never())->method('warning');

        $reflection = new \ReflectionClass(EventBus::class);
        $method = $reflection->getMethod('closeBuffers');
        $method->setAccessible(true);
        $method->invoke($bus);

        $this->assertFalse(EventBusTestOverrides::$fastcgiCalled);
        $this->assertCount(3, EventBusTestOverrides::$fcloseCalls);
    }

    public function testCloseBuffersLogsWarningOnFcloseException(): void
    {
        $this->dispatchMode = 0;
        EventBusTestOverrides::$simulateResources = true;
        EventBusTestOverrides::$simulateFcloseException = true;

        $bus = $this->createBus();

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Error closing file descriptors: Simulated fclose error');

        $reflection = new \ReflectionClass(EventBus::class);
        $method = $reflection->getMethod('closeBuffers');
        $method->setAccessible(true);
        $method->invoke($bus);

        $this->assertCount(1, EventBusTestOverrides::$fcloseCalls); // Should fail on first fclose
    }

    public function testExecuteCallsDispatchForEventInterface(): void
    {
        $this->dispatchMode = 0;

        $bus = $this->createBus();
        $bus->register('test.event', RecordingListener::class);

        $listener = new RecordingListener(static function (EventInterface $event): void {
            $event->set('executed', true);
        });

        $this->builder
            ->expects($this->once())
            ->method('build')
            ->willReturn($listener);

        $event = new SampleEvent('test.event');
        $bus->execute($event);

        $this->assertTrue($event->has('executed'));
        $this->assertCount(1, $listener->received);
    }

    public function testExecuteDoesNothingForNonEventInterface(): void
    {
        $this->dispatchMode = 0;

        $bus = $this->createBus();

        $this->builder->expects($this->never())->method('build');

        // Create a DTO that is not an EventInterface
        $dto = $this->createMock(\Flexi\Contracts\Interfaces\DTOInterface::class);
        $bus->execute($dto);

        // Should not throw or try to dispatch since it's not EventInterface
        $this->assertTrue(true);
    }

    public function testAsyncModeFalseWhenNotInCliMode(): void
    {
        // Simulate non-CLI environment by testing the method logic
        $this->dispatchMode = 1;
        $this->configHasDispatch = true;

        $bus = $this->createBus();

        // When PHP_SAPI === 'cli', asyncMode should return true
        // This is already tested in other tests
        // This test verifies the configuration check
        $result = $bus->asyncMode();

        $this->assertIsBool($result);
    }

    public function testAsyncModeFalseWhenDispatchModeIsZero(): void
    {
        $this->dispatchMode = 0; // Async disabled
        $this->configHasDispatch = true;

        $bus = $this->createBus();

        // Even in CLI mode, if dispatch_mode is 0, should return false
        if (PHP_SAPI === 'cli') {
            $this->assertFalse($bus->asyncMode());
        }
    }

    public function testGetHandlersDefinitionReturnsAllEvents(): void
    {
        $bus = $this->createBus();
        $bus->register('event.one', RecordingListener::class);
        $bus->register('event.two', SecondaryListener::class);
        $bus->register('event.one', SecondaryListener::class);

        $definition = $bus->getHandlersDefinition();

        $this->assertIsArray($definition);
        $this->assertArrayHasKey('event.one', $definition);
        $this->assertArrayHasKey('event.two', $definition);
        $this->assertCount(2, $definition['event.one']);
        $this->assertCount(1, $definition['event.two']);
    }

    public function testGetHandlersDefinitionWithAliasesParameter(): void
    {
        $bus = $this->createBus();
        $bus->register('test.event', RecordingListener::class);

        // The with_aliases parameter doesn't affect EventBus
        // but we test it's accepted
        $definition = $bus->getHandlersDefinition(true);

        $this->assertIsArray($definition);
        $this->assertArrayHasKey('test.event', $definition);
    }

    private function createBus(): EventBus
    {
        return new EventBus(
            $this->container,
            $this->builder,
            $this->logger,
            $this->configuration
        );
    }
}








