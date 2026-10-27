<?php

declare(strict_types=1);

namespace Flexi\Application\Services;

use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Domain\Interfaces\CandidateConfigurationFilesProviderInterface;
use Flexi\Domain\Interfaces\ModuleDetectorInterface;
use Flexi\Domain\Interfaces\ModuleStateManagerInterface;
use Flexi\Domain\ValueObjects\ModuleInfo;
use Flexi\Infrastructure\Classes\ModuleCandidateConfigurationFilesProvider;
use Flexi\Infrastructure\Classes\ModuleDependencyValidator;
use Flexi\Infrastructure\Classes\ModuleManifestValidator;
use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Infrastructure\Factories\BusFactory;
use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Infrastructure\Factories\RouterFactory;
use Flexi\Infrastructure\Bus\CommandBus;
use Flexi\Infrastructure\Bus\EventBus;
use Flexi\Infrastructure\Bus\QueryBus;
use Psr\Http\Message\ResponseFactoryInterface;

/** Builds a temporary runtime containing a candidate module without persisting its activation state. */
final class ModuleActivationPreflight implements ModuleActivationPreflightInterface
{
    private CacheInterface $cache;
    private ObjectBuilderInterface $objectBuilder;
    private ServicesDefinitionParser $servicesParser;
    private CandidateConfigurationFilesProviderInterface $configurationFilesProvider;
    private ModuleDetectorInterface $moduleDetector;
    private ModuleStateManagerInterface $moduleStateManager;

    public function __construct(
        CacheInterface $cache,
        ObjectBuilderInterface $objectBuilder,
        ServicesDefinitionParser $servicesParser,
        CandidateConfigurationFilesProviderInterface $configurationFilesProvider,
        ModuleDetectorInterface $moduleDetector,
        ModuleStateManagerInterface $moduleStateManager
    ) {
        $this->cache = $cache;
        $this->objectBuilder = $objectBuilder;
        $this->servicesParser = $servicesParser;
        $this->configurationFilesProvider = $configurationFilesProvider;
        $this->moduleDetector = $moduleDetector;
        $this->moduleStateManager = $moduleStateManager;
    }

    public function validate(ModuleInfo $module): void
    {
        if ($module->hasConflict()) {
            throw new \RuntimeException(sprintf(
                'Module "%s" has conflicting local and vendor sources; resolve the duplicate before activation.',
                $module->getName()
            ));
        }

        (new ModuleManifestValidator())->assertValid($module);

        $configurationFiles = $this->configurationFilesProvider->getAllConfigurationFilesIncluding($module->getName());
        $candidateProvider = new ModuleCandidateConfigurationFilesProvider(
            $this->configurationFilesProvider,
            $configurationFiles
        );

        $dependencyValidator = new ModuleDependencyValidator(
            $this->moduleDetector,
            $this->moduleStateManager,
            getcwd() ?: './',
            $this->getCoreVersion()
        );
        $dependencyValidator->validate([$module->getName()]);

        $container = (new ContainerFactory(
            $this->cache,
            $this->objectBuilder,
            $this->servicesParser,
            $candidateProvider
        ))->getInstance();

        $handlersParser = $container->get(\Flexi\Infrastructure\DependencyInjection\HandlersDefinitionParser::class);
        $listenersParser = $container->get(\Flexi\Infrastructure\DependencyInjection\ListenersDefinitionParser::class);
        $busFactory = new BusFactory($container, $handlersParser, $listenersParser, $candidateProvider);
        $eventBus = $busFactory->getInstance(EventBus::class);
        $busFactory->getInstance(CommandBus::class);
        $busFactory->getInstance(QueryBus::class);

        $routerFactory = new RouterFactory(
            $eventBus,
            $container->get(ObjectBuilderInterface::class),
            $container->get(ResponseFactoryInterface::class),
            $container,
            $container->get(\Flexi\Infrastructure\DependencyInjection\RoutesDefinitionParser::class),
            $candidateProvider
        );
        $routerFactory->getInstance();
    }

    private function getCoreVersion(): string
    {
        $rootComposer = getcwd() . DIRECTORY_SEPARATOR . 'composer.json';
        if (!is_file($rootComposer)) {
            return '0.0.0';
        }

        try {
            $data = json_decode((string) file_get_contents($rootComposer), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['version']) && is_string($data['version']) ? $data['version'] : '0.0.0';
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Unable to read root Composer version for module activation preflight.', 0, $exception);
        }
    }
}
