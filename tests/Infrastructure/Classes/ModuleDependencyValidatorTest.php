<?php

declare(strict_types=1);

namespace Flexi\Test\Infrastructure\Classes;

use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use Flexi\Infrastructure\Classes\ModuleDependencyValidator;
use PHPUnit\Framework\TestCase;

final class ModuleDependencyValidatorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/flexi-module-dependencies-' . uniqid('', true);
        mkdir($this->temporaryDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->temporaryDirectory);
    }

    public function testAcceptsCompatibleAcyclicActiveModuleDependencies(): void
    {
        $foundation = $this->createModule('Foundation', 'acme/flexi-foundation', '1.2.0');
        $shop = $this->createModule('Shop', 'acme/flexi-shop', '2.0.0', [
            'acme/flexi-foundation' => '^1.0',
        ]);
        $this->writeLockVersions([
            'acme/flexi-foundation' => '1.2.0',
            'acme/flexi-shop' => '2.0.0',
        ]);

        $this->createValidator([$foundation, $shop], ['Foundation', 'Shop'])->validate();

        $this->assertTrue(true);
    }

    public function testRejectsModuleOutsideItsDeclaredCoreCompatibility(): void
    {
        $module = $this->createModule('Shop', 'acme/flexi-shop', '1.0.0', [], '^4.0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires Flexi Core "^4.0"');
        $this->createValidator([$module], ['Shop'])->validate();
    }

    public function testRejectsUnsatisfiedModuleVersionConstraint(): void
    {
        $foundation = $this->createModule('Foundation', 'acme/flexi-foundation', '1.2.0');
        $shop = $this->createModule('Shop', 'acme/flexi-shop', '2.0.0', [
            'acme/flexi-foundation' => '^2.0',
        ]);
        $this->writeLockVersions(['acme/flexi-foundation' => '1.2.0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires "acme/flexi-foundation ^2.0"');
        $this->createValidator([$foundation, $shop], ['Foundation', 'Shop'])->validate();
    }

    public function testRejectsRequiredModuleThatIsNotActive(): void
    {
        $foundation = $this->createModule('Foundation', 'acme/flexi-foundation', '1.2.0');
        $shop = $this->createModule('Shop', 'acme/flexi-shop', '2.0.0', [
            'acme/flexi-foundation' => '^1.0',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('module "Foundation" (acme/flexi-foundation), but that module is not active');
        $this->createValidator([$foundation, $shop], ['Shop'])->validate();
    }

    public function testRejectsCircularActiveModuleDependenciesWithCyclePath(): void
    {
        $one = $this->createModule('One', 'acme/flexi-one', '1.0.0', ['acme/flexi-two' => '^1.0']);
        $two = $this->createModule('Two', 'acme/flexi-two', '1.0.0', ['acme/flexi-one' => '^1.0']);
        $this->writeLockVersions(['acme/flexi-one' => '1.0.0', 'acme/flexi-two' => '1.0.0']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular module dependency detected: One -> Two -> One.');
        $this->createValidator([$one, $two], ['One', 'Two'])->validate();
    }

    private function createValidator(array $modules, array $activeModuleNames): ModuleDependencyValidator
    {
        $detector = $this->createMock(ModuleDetectorInterface::class);
        $detector->method('getAllModules')->willReturn($modules);

        $stateManager = $this->createMock(ModuleStateManagerInterface::class);
        $stateManager->method('isModuleActive')->willReturnCallback(function (string $name) use ($activeModuleNames): bool {
            return in_array($name, $activeModuleNames, true);
        });

        return new ModuleDependencyValidator($detector, $stateManager, $this->temporaryDirectory, '3.1.0');
    }

    private function createModule(string $name, string $package, string $version, array $requirements = [], string $coreConstraint = '^3.0'): ModuleInfo
    {
        $directory = $this->temporaryDirectory . '/' . $name;
        mkdir($directory);
        file_put_contents($directory . '/composer.json', json_encode([
            'name' => $package,
            'version' => $version,
            'require' => $requirements,
            'extra' => ['flexi' => ['compatibility' => ['core' => $coreConstraint]]],
        ], JSON_THROW_ON_ERROR));

        return new ModuleInfo($name, $package, ModuleType::local(), $directory, $version);
    }

    private function writeLockVersions(array $versions): void
    {
        $packages = [];
        foreach ($versions as $name => $version) {
            $packages[] = ['name' => $name, 'version' => $version];
        }
        file_put_contents($this->temporaryDirectory . '/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    }
}
