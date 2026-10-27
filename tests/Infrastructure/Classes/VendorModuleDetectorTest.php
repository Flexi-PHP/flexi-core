<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Flexi\Domain\Interfaces\ModuleCacheManagerInterface;
use PHPUnit\Framework\TestCase;

final class VendorModuleDetectorTest extends TestCase
{
    private string $vendorDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendorDir = sys_get_temp_dir() . '/vendor_detector_' . uniqid();
        mkdir($this->vendorDir);

        VendorModuleDetector::clearCache();
    }

    protected function tearDown(): void
    {
        VendorModuleDetector::clearCache();
        $this->removeDirectory($this->vendorDir);

        parent::tearDown();
    }

    public function testGetAllModulesReturnsCachedEntriesWhenCacheValid(): void
    {
        $cachedModules = [
            new ModuleInfo(
                'Analytics',
                'vendor/analytics',
                ModuleType::vendor(),
                '/vendor/analytics',
                '1.1.0',
                false,
                ['type' => 'library']
            ),
            new ModuleInfo(
                'LocalTool',
                'vendor/local-tool',
                ModuleType::local(),
                '/modules/local-tool',
                'dev-main',
                false,
                ['type' => 'local']
            ),
        ];

        $cacheManager = new FakeCacheManager(true, $cachedModules);
        $detector = new VendorModuleDetector($cacheManager, $this->vendorDir);

        $modules = $detector->getAllModules();

        $this->assertSame(['Analytics'], array_keys($modules));
        $this->assertSame('vendor/analytics', $modules['Analytics']->getPackage());
        $this->assertTrue($detector->isModuleInstalled('analytics'));
        $this->assertInstanceOf(ModuleInfo::class, $detector->getModuleInfo('Analytics'));
        $this->assertSame(0, $cacheManager->cacheModulesCalls);
    }

    public function testScanVendorDiscoversModulesAndCachesResults(): void
    {
        $cacheManager = new FakeCacheManager(false, []);
        $detector = new VendorModuleDetector($cacheManager, $this->vendorDir);

        $packagePath = $this->createVendorPackage(
            'acme',
            'flexi-module-sample',
            [
                'name' => 'acme/flexi-module-sample',
                'version' => '2.3.4',
                'description' => 'Sample vendor module',
                'type' => 'library',
                'extra' => [
                    'flexi-module' => [
                        'category' => 'analytics'
                    ]
                ]
            ]
        );

        $modules = $detector->getAllModules();

        $this->assertCount(1, $modules);
        $this->assertArrayHasKey('Sample', $modules);
        $this->assertSame($packagePath, $modules['Sample']->getPath());
        $this->assertSame('2.3.4', $modules['Sample']->getVersion());
        $this->assertTrue($detector->isModuleInstalled('sample'));

        $stats = $detector->getModuleStatistics();
        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['vendor']);
        $this->assertSame(['library' => 1], $stats['by_type']);

        $this->assertSame(1, $cacheManager->cacheModulesCalls);
        $this->assertCount(1, $cacheManager->cachedPayload);
    }

    public function testScanVendorReadsPublishedFlexiMetadataFormat(): void
    {
        $cacheManager = new FakeCacheManager(false, []);
        $detector = new VendorModuleDetector($cacheManager, $this->vendorDir);

        $this->createVendorPackage(
            'cubadevops',
            'flexi-module-auth',
            [
                'name' => 'cubadevops/flexi-module-auth',
                'version' => '1.0.0',
                'type' => 'flexi-module',
                'extra' => [
                    'flexi' => [
                        'module-name' => 'Auth',
                        'config-files' => ['Config/routes.json', 'Config/services.json'],
                    ],
                ],
            ]
        );

        $modules = $detector->getAllModules();

        $this->assertArrayHasKey('Auth', $modules);
        $this->assertSame(
            ['module-name' => 'Auth', 'config-files' => ['Config/routes.json', 'Config/services.json']],
            $modules['Auth']->getMetadataValue('flexi')
        );
    }

    public function testRefreshModulesInvalidatesCacheAndClearsStaticState(): void
    {
        $cachedModules = [
            new ModuleInfo(
                'Analytics',
                'vendor/analytics',
                ModuleType::vendor(),
                '/vendor/analytics',
                '1.1.0',
                false,
                ['type' => 'library']
            ),
        ];

        $cacheManager = new FakeCacheManager(true, $cachedModules);
        $detector = new VendorModuleDetector($cacheManager, $this->vendorDir);

        $this->assertTrue($detector->isModuleInstalled('analytics'));

        $cacheManager->valid = false;
        $cacheManager->cachedModules = [];

        $detector->refreshModules();

        $this->assertTrue($cacheManager->invalidateCalled);
        $this->assertFalse($detector->isModuleInstalled('analytics'));
        $this->assertSame(1, $cacheManager->cacheModulesCalls);
    }

    private function createVendorPackage(string $vendor, string $package, array $composerData): string
    {
        $vendorPath = $this->vendorDir . '/' . $vendor;
        if (!is_dir($vendorPath)) {
            mkdir($vendorPath, 0777, true);
        }

        $packagePath = $vendorPath . '/' . $package;
        mkdir($packagePath);

        $composerJson = $composerData;
        if (isset($composerJson['extra']['flexi-module']) && !isset($composerJson['extra']['flexi-module']['name'])) {
            // Derive module name behaviour exercise
            unset($composerJson['extra']['flexi-module']['name']);
        }

        file_put_contents(
            $packagePath . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT)
        );

        // Add non-module package to ensure it is ignored
        if (!is_dir($vendorPath . '/ignored')) {
            mkdir($vendorPath . '/ignored');
            file_put_contents($vendorPath . '/ignored/composer.json', json_encode(['name' => 'acme/ignored']));
        }

        return $packagePath;
    }

    private function removeDirectory(string $path): void
    {
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
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}

final class FakeCacheManager implements ModuleCacheManagerInterface
{
    public bool $valid;

    /** @var ModuleInfo[] */
    public array $cachedModules;

    public int $cacheModulesCalls = 0;

    /** @var ModuleInfo[] */
    public array $cachedPayload = [];

    public bool $invalidateCalled = false;

    public bool $cacheExists = false;

    public string $cacheFilePath;

    public function __construct(bool $valid, array $cachedModules)
    {
        $this->valid = $valid;
        $this->cachedModules = $cachedModules;
        $this->cacheFilePath = sys_get_temp_dir() . '/fake_cache_' . uniqid() . '.json';
    }

    public function getCachedModules(?string $type = null): array
    {
        if ($type === null) {
            return $this->cachedModules;
        }

        // Filter by type
        return array_filter($this->cachedModules, function($module) use ($type) {
            return $module->getType()->getValue() === $type;
        });
    }

    public function cacheModules(array $modules, string $type): bool
    {
        $this->cacheModulesCalls++;
        $this->cachedPayload = $modules;
        return true;
    }

    public function isCacheValid(): bool
    {
        return $this->valid;
    }

    public function invalidateCache(): bool
    {
        $this->invalidateCalled = true;
        $this->valid = false;
        return true;
    }

    public function getCacheFilePath(): string
    {
        return $this->cacheFilePath;
    }

    public function cacheExists(): bool
    {
        return $this->cacheExists;
    }
}
