<?php

declare(strict_types=1);

namespace Flexi\Test\Application\UseCase;

use Flexi\Application\Commands\PublishModuleCommand;
use Flexi\Application\Services\ComposerProcessInterface;
use Flexi\Application\UseCase\PublishModule;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Domain\ValueObjects\ModuleType;
use PHPUnit\Framework\TestCase;

final class PublishModuleTest extends TestCase
{
    private string $root;
    private string $vendorPackage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/publish_module_' . uniqid();
        $this->vendorPackage = $this->root . '/vendor/acme/flexi-auth';
        mkdir($this->vendorPackage, 0777, true);
        file_put_contents($this->vendorPackage . '/composer.json', json_encode([
            'name' => 'acme/flexi-auth',
            'type' => 'flexi-module',
            'require' => ['php' => '>=7.4'],
            'extra' => ['flexi' => [
                'schema-version' => 1,
                'module-name' => 'Auth',
                'compatibility' => ['core' => '^3.0'],
                'config-files' => [],
                'provides' => [],
            ]],
        ], JSON_PRETTY_PRINT));
        mkdir($this->root . '/modules', 0777, true);
        $this->writeRootManifests();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPublishesVendorModuleThroughComposerPathRepositoryWithoutActivatingIt(): void
    {
        $localModule = $this->module($this->root . '/modules/Auth', ModuleType::local());
        $detector = $this->createMock(ModuleDetectorInterface::class);
        $detector->expects($this->once())->method('getModuleInfo')->with('Auth')->willReturn($this->module($this->vendorPackage, ModuleType::vendor()));
        $detector->expects($this->once())->method('getAllModules')->willReturn([$localModule]);

        $state = $this->createMock(ModuleStateManagerInterface::class);
        $state->expects($this->once())->method('syncWithDiscoveredModules')->with([$localModule]);

        $composer = new RecordingComposerProcess();
        $message = (new PublishModule($detector, $state, $composer, $this->root))->handle(new PublishModuleCommand(['module_name' => 'Auth']));
        $result = json_decode((string) $message, true);

        $this->assertTrue($result['success']);
        $this->assertFileExists($this->root . '/modules/Auth/composer.json');
        $root = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        $this->assertSame('./modules/Auth', $root['repositories'][0]['url']);
        $this->assertSame('1.2.3', $root['repositories'][0]['options']['versions']['acme/flexi-auth']);
        $this->assertSame(['update', 'acme/flexi-auth', '--no-interaction', '--no-progress', '--no-scripts'], $composer->calls[0]['arguments']);
        $this->assertCount(1, $composer->calls);
    }

    public function testComposerFailureRestoresManifestsRemovesCopyAndRunsRecoveryInstall(): void
    {
        $composerJson = (string) file_get_contents($this->root . '/composer.json');
        $composerLock = (string) file_get_contents($this->root . '/composer.lock');
        $detector = $this->createMock(ModuleDetectorInterface::class);
        $detector->expects($this->once())->method('getModuleInfo')->willReturn($this->module($this->vendorPackage, ModuleType::vendor()));
        $state = $this->createMock(ModuleStateManagerInterface::class);
        $state->expects($this->never())->method('syncWithDiscoveredModules');
        $composer = new RecordingComposerProcess([1, 0]);

        $result = json_decode((string) (new PublishModule($detector, $state, $composer, $this->root))->handle(new PublishModuleCommand(['module_name' => 'Auth'])), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Composer update failed', $result['error']);
        $this->assertSame($composerJson, file_get_contents($this->root . '/composer.json'));
        $this->assertSame($composerLock, file_get_contents($this->root . '/composer.lock'));
        $this->assertDirectoryDoesNotExist($this->root . '/modules/Auth');
        $this->assertSame('install', $composer->calls[1]['arguments'][0]);
    }

    private function writeRootManifests(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'acme/app', 'repositories' => []], JSON_PRETTY_PRINT));
        file_put_contents($this->root . '/composer.lock', json_encode(['packages' => [[
            'name' => 'acme/flexi-auth', 'version' => '1.2.3',
        ]], 'packages-dev' => []], JSON_PRETTY_PRINT));
    }

    private function module(string $path, ModuleType $type): ModuleInfo
    {
        return new ModuleInfo('Auth', 'acme/flexi-auth', $type, $path, '1.2.3');
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeDirectory($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        rmdir($path);
    }
}

final class RecordingComposerProcess implements ComposerProcessInterface
{
    public array $calls = [];
    private array $exitCodes;

    public function __construct(array $exitCodes = [0])
    {
        $this->exitCodes = $exitCodes;
    }

    public function run(array $arguments, string $workingDirectory): array
    {
        $this->calls[] = ['arguments' => $arguments, 'working_directory' => $workingDirectory];
        return ['exit_code' => array_shift($this->exitCodes) ?? 0, 'output' => 'simulated Composer output'];
    }
}
