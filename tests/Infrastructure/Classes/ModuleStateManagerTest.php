<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleState;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\ModuleStateManager;
use Flexi\Infrastructure\Classes\ModuleStateRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ModuleStateManagerTest extends TestCase
{
    public function testActivateAndDeactivateModuleSwitchesState(): void
    {
        $repository = new InMemoryModuleStateRepository();
        $manager = new ModuleStateManager($repository);

        $this->assertFalse($manager->isModuleActive('blog'));

        $this->assertTrue($manager->activateModule('blog', 'cli-user'));

        $state = $manager->getModuleState('blog');
        $this->assertInstanceOf(ModuleState::class, $state);
        $this->assertTrue($state->isActive());
        $this->assertSame('cli-user', $state->getMetadataValue('modifiedBy'));

        $this->assertTrue($manager->deactivateModule('blog', 'ops-user'));
        $this->assertFalse($manager->isModuleActive('blog'));

        $state = $manager->getModuleState('blog');
        $this->assertInstanceOf(ModuleState::class, $state);
        $this->assertSame('ops-user', $state->getModifiedBy());
    }

    public function testInitializeModuleStateHandlesTypeChanges(): void
    {
        $repository = new InMemoryModuleStateRepository();
        $manager = new ModuleStateManager($repository);

        $manager->setModuleState(ModuleState::createInactive('legacy', ModuleType::vendor()));
        $manager->setModuleState(ModuleState::createActive('analytics', ModuleType::local()));

        $this->assertTrue($manager->initializeModuleState('legacy', ModuleType::vendor(), false, 'sync-user'));
        $legacyState = $manager->getModuleState('legacy');
        $this->assertInstanceOf(ModuleState::class, $legacyState);
        $this->assertTrue($legacyState->getType()->equals(ModuleType::vendor()));

        $this->assertTrue($manager->initializeModuleState('analytics', ModuleType::vendor(), true, 'sync-user'));
        $analyticsState = $manager->getModuleState('analytics');
        $this->assertInstanceOf(ModuleState::class, $analyticsState);
        $this->assertTrue($analyticsState->getType()->equals(ModuleType::vendor()));
        $this->assertSame('sync-user', $analyticsState->getModifiedBy());

        $allStates = $manager->getAllModuleStates();
        $this->assertCount(2, $allStates);
        $this->assertEqualsCanonicalizing(['legacy', 'analytics'], $manager->getAllKnownModules());
    }

    public function testBulkActivationAndDeactivationReturnPerModuleResults(): void
    {
        $repository = new InMemoryModuleStateRepository();
        $manager = new ModuleStateManager($repository);

        $manager->setModuleState(ModuleState::createInactive('alpha', ModuleType::local()));
        $manager->setModuleState(ModuleState::createInactive('beta', ModuleType::vendor()));

        $activationResults = $manager->activateModules(['alpha', 'beta'], 'bulk-op');
        $this->assertSame(['alpha', 'beta'], array_keys($activationResults));
        $this->assertTrue($activationResults['alpha']['success']);
        $this->assertSame('activate', $activationResults['alpha']['action']);
        $this->assertTrue($manager->getModuleState('alpha')->isActive());
        $this->assertSame('bulk-op', $manager->getModuleState('alpha')->getModifiedBy());

        $deactivationResults = $manager->deactivateModules(['alpha'], 'bulk-op');
        $this->assertFalse($manager->getModuleState('alpha')->isActive());
        $this->assertSame('deactivate', $deactivationResults['alpha']['action']);
    }

    public function testSyncWithDiscoveredModulesProducesSummary(): void
    {
        $repository = new InMemoryModuleStateRepository();
        $manager = new ModuleStateManager($repository);

        $manager->setModuleState(new ModuleState('beta', true, ModuleType::local()));
        $manager->setModuleState(ModuleState::createInactive('gamma', ModuleType::vendor()));

        $discoveredModules = [
            new ModuleInfo(
                'alpha',
                'vendor/alpha',
                ModuleType::local(),
                '/modules/alpha',
                '1.0.0',
                true,
                []
            ),
            new ModuleInfo(
                'beta',
                'vendor/beta',
                ModuleType::vendor(),
                '/modules/beta',
                '1.2.0',
                true,
                []
            ),
        ];

        $summary = $manager->syncWithDiscoveredModules($discoveredModules);

        $this->assertSame(1, $summary['initialized']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summary['removed']);
        $this->assertSame('initialized', $summary['actions']['alpha']);
        $this->assertSame('type_updated', $summary['actions']['beta']);
        $this->assertSame('removed', $summary['actions']['gamma']);
        $this->assertEmpty($summary['errors']);

        $this->assertFalse($manager->getModuleState('alpha')->isActive());
        $this->assertTrue($manager->getModuleState('beta')->getType()->equals(ModuleType::vendor()));
        $this->assertTrue($manager->getModuleState('beta')->isActive());
        $this->assertNull($manager->getModuleState('gamma'));
    }

    public function testInitializeModuleStateDefaultsToInactive(): void
    {
        $manager = new ModuleStateManager(new InMemoryModuleStateRepository());

        $this->assertTrue($manager->initializeModuleState('new-module', ModuleType::vendor()));
        $this->assertFalse($manager->isModuleActive('new-module'));
    }

    public function testImportExportStatisticsAndBackup(): void
    {
        $repository = new InMemoryModuleStateRepository();
        $manager = new ModuleStateManager($repository);

        $importPayload = [
            'version' => '2.0.0',
            'lastSync' => '2025-11-17T00:00:00+00:00',
            'modules' => [
                'alpha' => [
                    'active' => true,
                    'type' => 'local',
                    'lastModified' => '2025-11-17T00:00:00+00:00',
                    'modifiedBy' => 'import',
                    'metadata' => [],
                ],
                'beta' => [
                    'active' => false,
                    'type' => 'vendor',
                    'lastModified' => '2025-11-16T00:00:00+00:00',
                    'modifiedBy' => 'import',
                    'metadata' => [],
                ],
            ],
        ];

        $importResult = $manager->importStates($importPayload, true);

        $this->assertTrue($importResult['success']);
        $this->assertSame(2, $importResult['imported_count']);

        $statistics = $manager->getStatistics();
        $this->assertSame(1, $statistics['activeModules']);
        $this->assertSame(1, $statistics['inactiveModules']);
        $this->assertSame(2, $statistics['totalModules']);
        $this->assertSame('2.0.0', $statistics['version']);

        $exported = $manager->exportStates();
        $this->assertArrayHasKey('modules', $exported);
        $this->assertCount(2, $exported['modules']);

        $this->assertTrue($manager->clearAllStates());
        $this->assertSame([], $manager->getAllModuleStates());

        $this->assertTrue($manager->backup());
        $this->assertTrue($repository->wasBackedUp());
    }
}

final class InMemoryModuleStateRepository extends ModuleStateRepository
{
    /** @var array<string, ModuleState> */
    private array $states = [];

    private array $metadata = [
        'version' => '1.2.3',
        'lastSync' => null,
        'moduleCount' => 0,
        'fileExists' => true,
        'filePath' => 'memory',
        'fileSize' => 0,
    ];

    private bool $backedUp = false;

    public function __construct()
    {
        // Intentionally bypass parent file-based initialisation.
    }

    public function save(ModuleState $state): bool
    {
        $this->states[$state->getModuleName()] = $state;
        $this->metadata['moduleCount'] = count($this->states);
        $this->metadata['lastSync'] = $state->getLastModified()->format(DateTimeImmutable::ATOM);
        return true;
    }

    public function find(string $moduleName): ?ModuleState
    {
        return $this->states[$moduleName] ?? null;
    }

    /**
     * @return array<string, ModuleState>
     */
    public function findAll(): array
    {
        return $this->states;
    }

    public function exists(string $moduleName): bool
    {
        return isset($this->states[$moduleName]);
    }

    public function delete(string $moduleName): bool
    {
        if (!isset($this->states[$moduleName])) {
            return false;
        }

        unset($this->states[$moduleName]);
        $this->metadata['moduleCount'] = count($this->states);
        $this->metadata['lastSync'] = (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        return true;
    }

    public function clear(): bool
    {
        $this->states = [];
        $this->metadata['moduleCount'] = 0;
        $this->metadata['lastSync'] = null;
        return true;
    }

    public function exportData(): array
    {
        $modules = [];

        foreach ($this->states as $name => $state) {
            $modules[$name] = $state->toArray();
        }

        return [
            'version' => $this->metadata['version'],
            'lastSync' => $this->metadata['lastSync'],
            'modules' => $modules,
        ];
    }

    public function importData(array $data, bool $overwrite = false): bool
    {
        if ($overwrite) {
            $this->states = [];
        }

        foreach ($data['modules'] ?? [] as $name => $payload) {
            $this->states[$name] = ModuleState::fromArray($name, $payload);
        }

        if (isset($data['version'])) {
            $this->metadata['version'] = $data['version'];
        }

        $this->metadata['moduleCount'] = count($this->states);
        $this->metadata['lastSync'] = $data['lastSync'] ?? (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);

        return true;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function backup(): bool
    {
        $this->backedUp = true;
        return true;
    }

    public function wasBackedUp(): bool
    {
        return $this->backedUp;
    }
}
