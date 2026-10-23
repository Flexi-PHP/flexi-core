<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Classes;

use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\ModuleCacheManager;
use PHPUnit\Framework\TestCase;

final class ModuleCacheManagerTest extends TestCase
{
    private string $tempDir;
    private string $cacheDir;
    private string $composerLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/module_cache_' . uniqid();
        mkdir($this->tempDir);

        $this->cacheDir = $this->tempDir . '/cache';
        $this->composerLockPath = $this->tempDir . '/composer.lock';

        file_put_contents($this->composerLockPath, '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testCacheModulesCreatesFileAndRetrievesCachedModules(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);

        $local = new ModuleInfo(
            'local-module',
            'vendor/local-module',
            ModuleType::local(),
            '/modules/local',
            '1.0.0',
            true,
            ['category' => 'local']
        );

        $vendor = new ModuleInfo(
            'vendor-module',
            'vendor/vendor-module',
            ModuleType::vendor(),
            '/modules/vendor',
            '2.0.0',
            true,
            ['category' => 'vendor']
        );

        $this->assertTrue($manager->cacheModules([$local], 'local'));
        $this->assertTrue($manager->cacheModules([$vendor], 'vendor'));
        $this->assertTrue($manager->cacheExists());
        $this->assertTrue($manager->isCacheValid());

        $cached = $manager->getCachedModules();
        $this->assertCount(2, $cached);

        $cachedLocal = $manager->getCachedModules('local');
        $this->assertCount(1, $cachedLocal);
        $this->assertSame('local-module', $cachedLocal[0]->getName());
        $this->assertTrue($cachedLocal[0]->getType()->equals(ModuleType::local()));

        $cachedVendor = $manager->getCachedModules('vendor');
        $this->assertCount(1, $cachedVendor);
        $this->assertSame('vendor-module', $cachedVendor[0]->getName());
        $this->assertTrue($cachedVendor[0]->getType()->equals(ModuleType::vendor()));
    }

    public function testCacheInvalidationRemovesFile(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);

        $module = new ModuleInfo(
            'single',
            'vendor/single',
            ModuleType::mixed(),
            '/modules/single',
            '1.2.3',
            false,
            []
        );

        $manager->cacheModules([$module], 'local');
        $this->assertTrue($manager->cacheExists());
        $this->assertTrue($manager->invalidateCache());
        $this->assertFalse($manager->cacheExists());
    }

    public function testInvalidateCacheReturnsTrueWhenFileIsMissing(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);

        $this->assertFalse($manager->cacheExists());
        $this->assertTrue($manager->invalidateCache());
    }

    public function testInvalidCacheReturnsEmptyModules(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);

        $module = new ModuleInfo(
            'bad-cache',
            'vendor/bad',
            ModuleType::local(),
            '/modules/bad',
            '0.1.0',
            false,
            []
        );

        $manager->cacheModules([$module], 'local');

        // Change the lock contents while preserving its modification time.
        $mtime = filemtime($this->composerLockPath);
        file_put_contents($this->composerLockPath, '{"packages":[]}');
        touch($this->composerLockPath, $mtime);

        $this->assertFalse($manager->isCacheValid());
        $this->assertSame([], $manager->getCachedModules());
    }

    public function testModuleManifestContentInvalidatesDiscoveryCache(): void
    {
        $modulePath = $this->tempDir . '/modules/example';
        mkdir($modulePath, 0755, true);
        $manifestPath = $modulePath . '/composer.json';
        file_put_contents($manifestPath, '{"name":"acme/example","version":"1.0.0"}');

        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $module = new ModuleInfo('example', 'acme/example', ModuleType::local(), $modulePath, '1.0.0');
        $this->assertTrue($manager->cacheModules([$module], 'local'));
        $this->assertTrue($manager->isCacheValid());

        file_put_contents($manifestPath, '{"name":"acme/renamed","version":"2.0.0"}');

        $this->assertFalse($manager->isCacheValid());
    }

    public function testAddingModuleInvalidatesDiscoveryCache(): void
    {
        $moduleRoot = $this->tempDir . '/modules';
        $firstModulePath = $moduleRoot . '/first';
        mkdir($firstModulePath, 0755, true);
        file_put_contents($firstModulePath . '/composer.json', '{"name":"acme/first"}');

        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $module = new ModuleInfo('first', 'acme/first', ModuleType::local(), $firstModulePath, '1.0.0');
        $this->assertTrue($manager->cacheModules([$module], 'local'));
        $this->assertTrue($manager->isCacheValid());

        $secondModulePath = $moduleRoot . '/second';
        mkdir($secondModulePath, 0755, true);
        file_put_contents($secondModulePath . '/composer.json', '{"name":"acme/second"}');

        $this->assertFalse($manager->isCacheValid());
    }

    public function testCorruptedCacheReturnsEmptyArray(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $cachePath = $manager->getCacheFilePath();
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cachePath, '{ invalid json');

        $this->assertSame([], $manager->getCachedModules());
        $this->assertFalse($manager->isCacheValid());
    }

    public function testCacheModulesFailsWhenCacheDirectoryCannotBeCreated(): void
    {
        $blockingPath = $this->tempDir . '/blocked-cache-dir';
        file_put_contents($blockingPath, 'blocker');

        $manager = new ModuleCacheManager($blockingPath, $this->tempDir);

        $module = new ModuleInfo(
            'blocked',
            'vendor/blocked',
            ModuleType::local(),
            '/modules/blocked',
            '0.0.1',
            false,
            []
        );

        $this->assertFalse($manager->cacheModules([$module], 'local'));
        $this->assertFalse($manager->cacheExists());
    }

    public function testCacheModulesReturnsFalseWhenJsonEncodingFails(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);

        $module = new ModuleInfo(
            'invalid-json',
            'vendor/invalid-json',
            ModuleType::local(),
            '/modules/invalid',
            '1.0.0',
            true,
            ['invalid' => INF]
        );

        $this->assertFalse($manager->cacheModules([$module], 'local'));
        $this->assertFalse($manager->cacheExists());
    }

    public function testGetCachedModulesHandlesMissingModulesStructure(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $cachePath = $manager->getCacheFilePath();
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $content = json_encode([
            'timestamp' => time(),
            'composer_lock_mtime' => filemtime($this->composerLockPath),
            'composer_lock_hash' => hash_file('sha256', $this->composerLockPath),
            'module_manifest_hashes' => [],
            'module_root_hashes' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($cachePath, $content);

        $this->assertSame([], $manager->getCachedModules());
        $this->assertTrue($manager->isCacheValid());
    }

    public function testGetCachedModulesFillsDefaultsForMissingFields(): void
    {
        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $cachePath = $manager->getCacheFilePath();
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $moduleRoot = $this->tempDir . '/modules';
        $modulePath = $moduleRoot . '/partial';
        mkdir($modulePath, 0755, true);
        file_put_contents($modulePath . '/composer.json', '{}');
        $manifestRootHashes = [
            'local' => [
                $moduleRoot => hash('sha256', json_encode([
                    $modulePath => hash_file('sha256', $modulePath . '/composer.json'),
                ])),
            ],
        ];
        $content = json_encode([
            'timestamp' => time(),
            'composer_lock_mtime' => filemtime($this->composerLockPath),
            'composer_lock_hash' => hash_file('sha256', $this->composerLockPath),
            'module_manifest_hashes' => [$modulePath . '/composer.json' => hash_file('sha256', $modulePath . '/composer.json')],
            'module_root_hashes' => $manifestRootHashes,
            'modules_by_type' => [
                'local' => [
                    [
                        'name' => 'partial',
                        'type' => 'mixed',
                        'path' => $modulePath,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($cachePath, $content);

        $modules = $manager->getCachedModules();
        $this->assertCount(1, $modules);
        $module = $modules[0];
        $this->assertSame('partial', $module->getName());
        $this->assertSame('unknown', $module->getVersion());
        $this->assertSame([], $module->getMetadata());
        $this->assertTrue($module->getType()->equals(ModuleType::mixed()));
    }

    public function testIsCacheValidReturnsFalseWhenComposerLockMissing(): void
    {
        unlink($this->composerLockPath);

        $manager = new ModuleCacheManager($this->cacheDir, $this->tempDir);
        $module = new ModuleInfo(
            'no-lock',
            'vendor/no-lock',
            ModuleType::local(),
            '/modules/no-lock',
            null,
            false,
            []
        );

        $this->assertTrue($manager->cacheModules([$module], 'local'));
        $this->assertFalse($manager->isCacheValid());
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
                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
