<?php

declare(strict_types=1);

namespace Flexi\Test\Application\UseCase;

use Flexi\Application\Commands\DeactivateModuleCommand;
use Flexi\Application\UseCase\DeactivateModule;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Domain\Events\ModuleDeactivatedEvent;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleState;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Domain\Interfaces\ModuleEnvironmentManagerInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeactivateModuleTest extends TestCase
{
    /** @var ModuleStateManagerInterface&MockObject */
    private $stateManager;
    /** @var HybridModuleDetector&MockObject */
    private $moduleDetector;
    /** @var ModuleEnvironmentManagerInterface&MockObject */
    private $envManager;
    /** @var EventBusInterface&MockObject */
    private $eventBus;
    private DeactivateModule $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $this->moduleDetector = $this->createMock(HybridModuleDetector::class);
        $this->envManager = $this->createMock(ModuleEnvironmentManagerInterface::class);
        $this->eventBus = $this->createMock(EventBusInterface::class);
        $this->eventBus->method('dispatch')->willReturnCallback(static function ($event) {
            return $event;
        });

        $this->useCase = new DeactivateModule($this->stateManager, $this->moduleDetector, $this->envManager, $this->eventBus);
    }

    public function testHandleFailsWhenModuleNameMissing(): void
    {
        $payload = $this->decode($this->useCase->handle(new DeactivateModuleCommand()));

        $this->assertFalse($payload['success']);
        $this->assertSame('Module name is required', $payload['error']);
    }

    public function testHandleFailsWhenModuleNotFound(): void
    {
        $command = new DeactivateModuleCommand(['module_name' => 'catalog']);

        $this->moduleDetector
            ->expects($this->once())
            ->method('getModuleInfo')
            ->with('catalog')
            ->willReturn(null);

        $this->stateManager->expects($this->never())->method('isModuleActive');

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Module 'catalog' not found. Use 'modules:list' to see available modules.", $payload['error']);
    }

    public function testHandleFailsWhenModuleAlreadyInactive(): void
    {
        $command = new DeactivateModuleCommand(['module_name' => 'catalog']);
        $moduleInfo = $this->createModuleInfo(ModuleType::vendor());

        $this->moduleDetector->method('getModuleInfo')->with('catalog')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->with('catalog')->willReturn(false);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Module 'catalog' is already inactive", $payload['error']);
    }

    public function testHandleFailsWhenDeactivationProcessReturnsFalse(): void
    {
        $command = new DeactivateModuleCommand(['module_name' => 'catalog']);
        $moduleInfo = $this->createModuleInfo(ModuleType::local());

        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->willReturn(true);
        $this->stateManager->expects($this->once())->method('deactivateModule')->with('catalog', 'user')->willReturn(false);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Failed to deactivate module 'catalog'", $payload['error']);
    }

    public function testHandleDeactivatesModuleAndRemovesEnvironment(): void
    {
        $command = new DeactivateModuleCommand([
            'module_name' => 'catalog',
            'modified_by' => 'tester',
        ]);
        $moduleInfo = $this->createModuleInfo(ModuleType::mixed(), [
            'local_path' => '/modules/catalog',
            'vendor_path' => '/vendor/flexi/catalog',
        ]);
        $state = new ModuleState(
            'catalog',
            false,
            ModuleType::mixed(),
            new DateTimeImmutable('2025-01-05T12:00:00+00:00'),
            'tester'
        );

        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->willReturn(true);
        $this->stateManager->expects($this->once())->method('deactivateModule')->with('catalog', 'tester')->willReturn(true);
        $this->eventBus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(ModuleDeactivatedEvent::class))->willReturnCallback(static function ($event) {
            return $event;
        });
        $this->stateManager->method('getModuleState')->willReturn($state);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvironment')
            ->with('catalog')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->envManager
            ->expects($this->once())
            ->method('removeModuleEnvironment')
            ->with('catalog')
            ->willReturn(true);

        $this->envManager
            ->expects($this->once())
            ->method('hasModuleEnvFile')
            ->with($moduleInfo->getPath())
            ->willReturn(true);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertTrue($payload['success']);
        $this->assertSame('deactivated', $payload['status']);
        $this->assertTrue($payload['details']['env_vars_removed']);
        $this->assertSame('Module exists in multiple locations. Deactivated from: /modules/catalog', $payload['info']);
    }

    public function testHandleReportsWarningWhenEnvironmentRemovalFails(): void
    {
        $command = new DeactivateModuleCommand(['module_name' => 'catalog']);
        $moduleInfo = $this->createModuleInfo(ModuleType::local());
        $state = new ModuleState('catalog', false);

        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->willReturn(true);
        $this->stateManager->method('deactivateModule')->willReturn(true);
        $this->stateManager->method('getModuleState')->willReturn($state);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvironment')
            ->with('catalog')
            ->willReturnOnConsecutiveCalls(true, true);

        $this->envManager
            ->expects($this->once())
            ->method('removeModuleEnvironment')
            ->with('catalog')
            ->willReturn(false);

        $this->envManager
            ->expects($this->once())
            ->method('hasModuleEnvFile')
            ->with($moduleInfo->getPath())
            ->willReturn(false);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertTrue($payload['success']);
        $this->assertSame(['Failed to remove module environment variables from main .env file'], $payload['env_warnings']);
        $this->assertFalse($payload['details']['env_vars_removed']);
    }

    private function createModuleInfo(ModuleType $type, array $metadata = []): ModuleInfo
    {
        return new ModuleInfo(
            'catalog',
            'flexi/catalog',
            $type,
            '/modules/catalog',
            '1.0.0',
            true,
            $metadata
        );
    }

    private function decode($message): array
    {
        $payload = json_decode($message->get('body'), true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
