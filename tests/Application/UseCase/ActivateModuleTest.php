<?php

declare(strict_types=1);

namespace Flexi\Test\Application\UseCase;

use Flexi\Application\Commands\ActivateModuleCommand;
use Flexi\Application\UseCase\ActivateModule;
use Flexi\Application\Services\ModuleActivationPreflightInterface;
use Flexi\Contracts\Interfaces\EventBusInterface;
use Flexi\Domain\Events\ModuleActivatedEvent;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleState;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Domain\Interfaces\ModuleEnvironmentManagerInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActivateModuleTest extends TestCase
{
    /** @var ModuleStateManagerInterface&MockObject */
    private $stateManager;
    /** @var HybridModuleDetector&MockObject */
    private $moduleDetector;
    /** @var ModuleEnvironmentManagerInterface&MockObject */
    private $envManager;
    /** @var ModuleActivationPreflightInterface&MockObject */
    private $preflight;
    /** @var EventBusInterface&MockObject */
    private $eventBus;
    private ActivateModule $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $this->stateManager->method('initializeModuleState')->willReturn(true);
        $this->moduleDetector = $this->createMock(HybridModuleDetector::class);
        $this->envManager = $this->createMock(ModuleEnvironmentManagerInterface::class);
        $this->preflight = $this->createMock(ModuleActivationPreflightInterface::class);
        $this->eventBus = $this->createMock(EventBusInterface::class);
        $this->eventBus->method('dispatch')->willReturnCallback(static function ($event) {
            return $event;
        });

        $this->useCase = new ActivateModule($this->stateManager, $this->moduleDetector, $this->envManager, $this->preflight, $this->eventBus);
    }

    public function testHandleFailsWhenModuleNameMissing(): void
    {
        $command = new ActivateModuleCommand();

        $result = $this->useCase->handle($command);
        $payload = json_decode($result->get('body'), true);

        $this->assertFalse($payload['success']);
        $this->assertSame('Module name is required', $payload['error']);
    }

    public function testHandleFailsWhenModuleNotFound(): void
    {
        $command = new ActivateModuleCommand(['module_name' => 'blog']);

        $this->moduleDetector
            ->expects($this->once())
            ->method('getModuleInfo')
            ->with('blog')
            ->willReturn(null);

        $this->stateManager->expects($this->never())->method('isModuleActive');

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Module 'blog' not found. Use 'modules:list' to see available modules.", $payload['error']);
    }

    public function testHandleFailsWhenModuleAlreadyActive(): void
    {
        $moduleInfo = $this->createModuleInfo(ModuleType::local());
        $command = new ActivateModuleCommand([
            'module_name' => 'analytics',
        ]);

        $this->moduleDetector
            ->expects($this->once())
            ->method('getModuleInfo')
            ->with('analytics')
            ->willReturn($moduleInfo);

        $this->stateManager
            ->expects($this->once())
            ->method('isModuleActive')
            ->with('analytics')
            ->willReturn(true);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Module 'analytics' is already active", $payload['error']);
    }

    public function testHandleFailsWhenActivationProcessReturnsFalse(): void
    {
        $moduleInfo = $this->createModuleInfo(ModuleType::local());
        $command = new ActivateModuleCommand([
            'module_name' => 'analytics',
        ]);

        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->with('analytics')->willReturn(false);
        $this->stateManager
            ->expects($this->once())
            ->method('activateModule')
            ->with('analytics', 'user')
            ->willReturn(false);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertFalse($payload['success']);
        $this->assertSame("Failed to activate module 'analytics'", $payload['error']);
    }

    public function testHandleDoesNotPersistActivationWhenPreflightFails(): void
    {
        $moduleInfo = $this->createModuleInfo(ModuleType::vendor());
        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->willReturn(false);
        $this->preflight->expects($this->once())
            ->method('validate')
            ->with($moduleInfo)
            ->willThrowException(new \RuntimeException('invalid route schema'));
        $this->stateManager->expects($this->never())->method('activateModule');
        $this->eventBus->expects($this->never())->method('dispatch');

        $payload = $this->decode($this->useCase->handle(new ActivateModuleCommand(['module_name' => 'analytics'])));

        $this->assertFalse($payload['success']);
        $this->assertSame('preflight_failed', $payload['status']);
        $this->assertStringContainsString('invalid route schema', $payload['error']);
    }

    public function testHandleActivatesModuleAndUpdatesEnvironment(): void
    {
        $moduleInfo = $this->createModuleInfo(ModuleType::mixed(), [
            'local_path' => '/modules/analytics',
            'vendor_path' => '/vendor/flexi/analytics',
        ]);
        $command = new ActivateModuleCommand([
            'module_name' => 'analytics',
            'modified_by' => 'tester',
        ]);
        $state = new ModuleState(
            'analytics',
            true,
            ModuleType::mixed(),
            new DateTimeImmutable('2025-01-01T10:00:00+00:00'),
            'tester'
        );

        $this->moduleDetector->method('getModuleInfo')->with('analytics')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->with('analytics')->willReturn(false);
        $this->preflight->expects($this->once())->method('validate')->with($moduleInfo);
        $this->stateManager->expects($this->once())->method('activateModule')->with('analytics', 'tester')->willReturn(true);
        $this->eventBus->expects($this->once())->method('dispatch')->with($this->isInstanceOf(ModuleActivatedEvent::class))->willReturnCallback(static function ($event) {
            return $event;
        });
        $this->stateManager->method('getModuleState')->with('analytics')->willReturn($state);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvFile')
            ->with($moduleInfo->getPath())
            ->willReturn(true);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvironment')
            ->with('analytics')
            ->willReturnOnConsecutiveCalls(true, true);

        $this->envManager
            ->expects($this->once())
            ->method('readModuleEnvironment')
            ->with($moduleInfo->getPath(), 'analytics')
            ->willReturn(['API_KEY' => '123']);

        $this->envManager
            ->expects($this->once())
            ->method('updateModuleEnvironment')
            ->with('analytics', ['API_KEY' => '123'])
            ->willReturn(true);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertTrue($payload['success']);
        $this->assertSame('analytics', $payload['module']);
        $this->assertSame('activated', $payload['status']);
        $this->assertSame('tester', $payload['details']['modified_by']);
        $this->assertTrue($payload['details']['env_vars_integrated']);
        $this->assertSame('Module exists in multiple locations. Using: /modules/analytics', $payload['warning']);
        $this->assertTrue($payload['details']['conflict']);
    }

    public function testHandleAddsEnvironmentAndReportsWarningWhenIntegrationFails(): void
    {
        $moduleInfo = $this->createModuleInfo(ModuleType::local());
        $command = new ActivateModuleCommand(['module_name' => 'analytics']);
        $state = new ModuleState('analytics');

        $this->moduleDetector->method('getModuleInfo')->willReturn($moduleInfo);
        $this->stateManager->method('isModuleActive')->willReturn(false);
        $this->preflight->expects($this->once())->method('validate')->with($moduleInfo);
        $this->stateManager->method('activateModule')->willReturn(true);
        $this->stateManager->method('getModuleState')->willReturn($state);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvFile')
            ->with($moduleInfo->getPath())
            ->willReturn(true);

        $this->envManager
            ->expects($this->exactly(2))
            ->method('hasModuleEnvironment')
            ->with('analytics')
            ->willReturnOnConsecutiveCalls(false, false);

        $this->envManager
            ->expects($this->once())
            ->method('readModuleEnvironment')
            ->willReturn(['TOKEN' => 'value']);

        $this->envManager
            ->expects($this->once())
            ->method('addModuleEnvironment')
            ->with('analytics', ['TOKEN' => 'value'])
            ->willReturn(false);

        $payload = $this->decode($this->useCase->handle($command));

        $this->assertTrue($payload['success']);
        $this->assertSame(['Failed to integrate module environment variables with main .env file'], $payload['env_warnings']);
        $this->assertFalse($payload['details']['env_vars_integrated']);
    }

    private function createModuleInfo(ModuleType $type, array $metadata = []): ModuleInfo
    {
        return new ModuleInfo(
            'analytics',
            'flexi/analytics',
            $type,
            '/modules/analytics',
            '1.0.0',
            false,
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
