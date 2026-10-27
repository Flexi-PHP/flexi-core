<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Flexi\Domain\Interfaces\ModuleCacheManagerInterface;
use PHPUnit\Framework\TestCase;

final class HybridModuleDetectorTest extends TestCase
{
    private string $modulesDir;
    private string $vendorDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/hybrid_detector_' . uniqid();
        $this->modulesDir = $base . '/modules';
        $this->vendorDir = $base . '/vendor';

        mkdir($this->modulesDir, 0777, true);
        mkdir($this->vendorDir, 0777, true);

        LocalModuleDetector::clearCache();
        VendorModuleDetector::clearCache();
    }

    protected function tearDown(): void
    {
        LocalModuleDetector::clearCache();
        VendorModuleDetector::clearCache();
        $this->removeDirectory(dirname($this->modulesDir));

        parent::tearDown();
    }

    public function testHybridDetectorMergesSourcesAndResolvesConflicts(): void
    {
        $this->createLocalModule('Analytics', [
            'name' => 'cubadevops/flexi-module-analytics',
            'version' => '1.0.0',
            'description' => 'Local analytics package',
        ]);
        $this->createLocalModule('Reports', [
            'name' => 'cubadevops/flexi-module-reports',
            'version' => '2.0.0',
        ]);

        $this->createVendorModule('cubadevops', 'flexi-module-analytics', [
            'name' => 'cubadevops/flexi-module-analytics',
            'version' => '1.1.0',
            'description' => 'Vendor analytics package',
            'extra' => [
                'flexi-module' => [
                    'name' => 'Analytics'
                ]
            ]
        ]);
        $this->createVendorModule('acme', 'payments-suite', [
            'name' => 'acme/payments-suite',
            'version' => '3.4.1',
            'extra' => [
                'flexi-module' => [
                    'name' => 'Payments'
                ]
            ]
        ]);

        $cacheManager = new NullCacheManager();
        $localDetector = new LocalModuleDetector($cacheManager, $this->modulesDir);
        $vendorDetector = new VendorModuleDetector($cacheManager, $this->vendorDir);

        $detector = new HybridModuleDetector($localDetector, $vendorDetector);

        $all = $detector->getAllModules();
        $this->assertCount(3, $all);
        $this->assertTrue(isset($all['Analytics']));
        $this->assertSame('Reports', $all['Reports']->getName());
        $this->assertSame('Payments', $all['Payments']->getName());

        $conflicts = $detector->getConflictedModules();
        $this->assertArrayHasKey('Analytics', $conflicts);
        $conflictInfo = $conflicts['Analytics'];
        $this->assertTrue($conflictInfo->getType()->equals(ModuleType::mixed()));
        $this->assertTrue($conflictInfo->getMetadata()['conflict']);
        $this->assertStringContainsString('/Analytics', $conflictInfo->getMetadata()['local_path']);
        $this->assertStringContainsString('/flexi-module-analytics', $conflictInfo->getMetadata()['vendor_path']);

        $this->assertTrue($detector->hasModuleConflict('analytics'));
        $this->assertTrue($detector->getModuleInstallationType('analytics')->equals(ModuleType::mixed()));
        $this->assertTrue($detector->getModuleInstallationType('reports')->equals(ModuleType::local()));
        $this->assertTrue($detector->getModuleInstallationType('payments')->equals(ModuleType::vendor()));

        $resolvedLocal = $detector->resolveConflict('analytics', ModuleType::local());
        $this->assertSame('1.0.0', $resolvedLocal->getVersion());
        $resolvedVendor = $detector->resolveConflict('analytics', ModuleType::vendor());
        $this->assertSame('1.1.0', $resolvedVendor->getVersion());

        $stats = $detector->getModuleStatistics();
        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['local_only']);
        $this->assertSame(1, $stats['vendor_only']);
        $this->assertSame(1, $stats['conflicts']);

        $this->assertCount(1, $detector->getLocalOnlyModules());
        $this->assertArrayHasKey('Reports', $detector->getLocalOnlyModules());
        $this->assertCount(1, $detector->getVendorOnlyModules());
        $this->assertArrayHasKey('Payments', $detector->getVendorOnlyModules());

        $detector->clearCache();
        $cacheManager->invalidateCache();

        $this->createLocalModule('Logs', []);
        $this->createVendorModule('acme', 'monitor-suite', [
            'name' => 'acme/monitor-suite',
            'version' => '1.0.0',
            'extra' => [
                'flexi-module' => [
                    'name' => 'Monitor'
                ]
            ]
        ]);

        $refreshed = $detector->getAllModules();
        $this->assertArrayHasKey('Logs', $refreshed);
        $this->assertArrayHasKey('Monitor', $refreshed);
    }

    public function testPathRepositorySymlinkToPublishedLocalModuleIsOneLocalSource(): void
    {
        $modulePath = $this->modulesDir . '/CustomAuth';
        mkdir($modulePath);
        file_put_contents($modulePath . '/composer.json', json_encode([
            'name' => 'acme/flexi-auth',
            'type' => 'flexi-module',
            'extra' => ['flexi' => ['module-name' => 'Auth']],
        ]));

        mkdir($this->vendorDir . '/acme', 0777, true);
        symlink($modulePath, $this->vendorDir . '/acme/flexi-auth');

        $cacheManager = new NullCacheManager();
        $detector = new HybridModuleDetector(
            new LocalModuleDetector($cacheManager, $this->modulesDir),
            new VendorModuleDetector($cacheManager, $this->vendorDir)
        );

        $this->assertCount(1, $detector->getAllModules());
        $this->assertSame('Auth', $detector->getAllModules()['Auth']->getName());
        $this->assertFalse($detector->hasModuleConflict('Auth'));
        $this->assertTrue($detector->getModuleInstallationType('Auth')->equals(ModuleType::local()));
        $this->assertSame([], $detector->getConflictedModules());
    }

    public function testDifferentLocalAndVendorSourcesRemainAnExplicitConflict(): void
    {
        $this->createLocalModule('CustomAuth', [
            'name' => 'acme/flexi-auth',
            'extra' => ['flexi' => ['module-name' => 'Auth']],
        ]);
        $this->createVendorModule('acme', 'flexi-auth', [
            'name' => 'acme/flexi-auth',
            'extra' => ['flexi' => ['module-name' => 'Auth']],
        ]);

        $cacheManager = new NullCacheManager();
        $detector = new HybridModuleDetector(
            new LocalModuleDetector($cacheManager, $this->modulesDir),
            new VendorModuleDetector($cacheManager, $this->vendorDir)
        );

        $this->assertTrue($detector->hasModuleConflict('Auth'));
        $this->assertTrue($detector->getModuleInfo('Auth')->hasConflict());
    }

    private function createLocalModule(string $name, array $data): void
    {
        $modulePath = $this->modulesDir . '/' . $name;
        mkdir($modulePath);

        file_put_contents(
            $modulePath . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function createVendorModule(string $vendor, string $package, array $data): void
    {
        $packagePath = $this->vendorDir . '/' . $vendor . '/' . $package;
        mkdir($packagePath, 0777, true);

        file_put_contents(
            $packagePath . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}

final class NullCacheManager implements ModuleCacheManagerInterface
{
    public bool $valid = false;
    /** @var array<string, array<int, \Flexi\Domain\ValueObjects\ModuleInfo>> */
    public array $modulesByType = [];

    public function getCachedModules(?string $type = null): array
    {
        if ($type === null) {
            $all = [];
            foreach ($this->modulesByType as $modules) {
                $all = array_merge($all, $modules);
            }
            return $all;
        }
        return $this->modulesByType[$type] ?? [];
    }

    public function cacheModules(array $modules, string $type): bool
    {
        $this->valid = true;
        $this->modulesByType[$type] = $modules;
        return true;
    }

    public function isCacheValid(): bool
    {
        return $this->valid;
    }

    public function invalidateCache(): bool
    {
        $this->valid = false;
        $this->modulesByType = [];
        return true;
    }

    public function getCacheFilePath(): string
    {
        return sys_get_temp_dir() . '/hybrid_cache_' . uniqid() . '.json';
    }

    public function cacheExists(): bool
    {
        return $this->valid;
    }
}
