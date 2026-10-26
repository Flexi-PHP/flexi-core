<?php

declare(strict_types=1);

use Flexi\Infrastructure\Classes\ConfigurationFilesProvider;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Infrastructure\Classes\InMemoryCache;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\ModuleCacheManager;
use Flexi\Infrastructure\Classes\ModuleStateManager;
use Flexi\Infrastructure\Classes\ModuleStateRepository;
use Flexi\Infrastructure\Classes\ObjectBuilder;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Infrastructure\Factories\RouterFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

final class BenchmarkHttpHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'ok');
    }
}

$iterations = isset($argv[1]) ? (int) $argv[1] : 100;
if ($iterations < 10) {
    fwrite(STDERR, "Use at least 10 iterations.\n");
    exit(2);
}

$warmups = max(5, (int) ceil($iterations * 0.1));
$coreRoot = dirname(__DIR__);
$results = [];

foreach ([false, true] as $withModule) {
    $root = sys_get_temp_dir() . '/flexi-runtime-benchmark-' . bin2hex(random_bytes(6));
    mkdir($root . '/src/Config', 0777, true);
    mkdir($root . '/modules', 0777, true);
    mkdir($root . '/var', 0777, true);

    foreach (glob($coreRoot . '/src/Config/*.json') as $configFile) {
        copy($configFile, $root . '/src/Config/' . basename($configFile));
    }
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'flexi/runtime-benchmark',
        'extra' => ['flexi' => ['module-load-order' => ['Benchmark']]],
    ], JSON_THROW_ON_ERROR));

    if ($withModule) {
        $module = $root . '/modules/benchmark';
        mkdir($module . '/config', 0777, true);
        file_put_contents($module . '/composer.json', json_encode([
            'name' => 'benchmark/flexi-module-benchmark',
            'version' => '1.0.0',
            'type' => 'flexi-module',
            'extra' => ['flexi' => [
                'module-name' => 'Benchmark',
                'config-files' => ['config/services.json', 'config/routes.json'],
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($module . '/config/services.json', json_encode([
            'services' => [[
                'name' => 'benchmark.marker',
                'class' => ['name' => stdClass::class],
            ]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($module . '/config/routes.json', json_encode([
            'routes' => [[
                'name' => 'benchmark.route',
                'path' => '/benchmark',
                'method' => 'GET',
                'controller' => BenchmarkHttpHandler::class,
                'middlewares' => [],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    $previousDirectory = getcwd();
    chdir($root);
    LocalModuleDetector::clearCache();
    VendorModuleDetector::clearCache();

    $stateManager = new ModuleStateManager(new ModuleStateRepository('./var/modules-state.json'));
    if ($withModule) {
        $stateManager->activateModule('Benchmark');
    }
    $moduleCache = new ModuleCacheManager('./var', './');
    $localDetector = new LocalModuleDetector($moduleCache);
    $vendorDetector = new VendorModuleDetector($moduleCache, './vendor');
    $moduleDetector = new HybridModuleDetector($localDetector, $vendorDetector);
    $filesProvider = new ConfigurationFilesProvider($stateManager, $moduleDetector, $root);
    $cache = new InMemoryCache();
    $factory = new ContainerFactory(
        $cache,
        new ObjectBuilder($cache),
        new ServicesDefinitionParser($cache),
        $filesProvider
    );

    $bootstrapSamples = [];
    $dispatchSamples = [];
    for ($index = 0; $index < $iterations + $warmups; ++$index) {
        $start = hrtime(true);
        $container = $factory->getInstance();
        $router = RouterFactory::create($container);
        $bootstrapTime = (hrtime(true) - $start) / 1_000_000;

        $request = new ServerRequest('GET', 'http://localhost' . ($withModule ? '/benchmark' : '/missing'));
        $start = hrtime(true);
        $response = $router->dispatch($request);
        $dispatchTime = (hrtime(true) - $start) / 1_000_000;
        $expectedStatus = $withModule ? 200 : 404;
        if ($response->getStatusCode() !== $expectedStatus) {
            throw new RuntimeException(sprintf('Expected HTTP %d, received %d.', $expectedStatus, $response->getStatusCode()));
        }

        if ($index >= $warmups) {
            $bootstrapSamples[] = $bootstrapTime;
            $dispatchSamples[] = $dispatchTime;
        }
    }

    $results[$withModule ? 'with_active_module' : 'minimal'] = [
        'bootstrap_median_ms' => percentile($bootstrapSamples, 0.50),
        'bootstrap_p95_ms' => percentile($bootstrapSamples, 0.95),
        'dispatch_median_ms' => percentile($dispatchSamples, 0.50),
        'dispatch_p95_ms' => percentile($dispatchSamples, 0.95),
    ];

    chdir($previousDirectory);
    removeDirectory($root);
}

echo json_encode([
    'php' => PHP_VERSION,
    'iterations' => $iterations,
    'warmups' => $warmups,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

/** @param float[] $samples */
function percentile(array $samples, float $percentile): float
{
    sort($samples);
    $index = (int) ceil($percentile * count($samples)) - 1;
    return round($samples[max(0, $index)], 3);
}

function removeDirectory(string $path): void
{
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $itemPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($itemPath)) {
            removeDirectory($itemPath);
        } else {
            unlink($itemPath);
        }
    }
    rmdir($path);
}
