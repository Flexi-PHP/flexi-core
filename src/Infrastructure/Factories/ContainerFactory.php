<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Factories;

use Flexi\Infrastructure\Classes\ModuleCacheManager;
use Flexi\Infrastructure\Classes\ModuleStateRepository;
use Flexi\Contracts\Interfaces\CacheInterface;
use Flexi\Contracts\Interfaces\ObjectBuilderInterface;
use Flexi\Infrastructure\DependencyInjection\ServicesDefinitionParser;
use Flexi\Infrastructure\DependencyInjection\ConfigurationValidator;
use Flexi\Infrastructure\Classes\Configuration;
use Flexi\Infrastructure\Classes\ConfigurationRepository;
use Flexi\Infrastructure\Classes\ObjectBuilder;
use Flexi\Infrastructure\DependencyInjection\Container;
use Flexi\Domain\Interfaces\ConfigurationFilesProviderInterface;
use Flexi\Domain\ValueObjects\ConfigurationType;
use Flexi\Infrastructure\Classes\ConfigurationFilesProvider;
use Flexi\Infrastructure\Classes\HybridModuleDetector;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\ModuleStateManager;
use Flexi\Infrastructure\Classes\ModuleDependencyValidator;
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContainerFactory
{
    private CacheInterface $cache;
    private ObjectBuilderInterface $object_builder;
    private ServicesDefinitionParser $services_definition_parser;
    private ConfigurationFilesProviderInterface $config_files_provider;
    private ?ModuleDependencyValidator $module_dependency_validator;

    public function __construct(
        CacheInterface $cache,
        ObjectBuilderInterface $object_builder,
        ServicesDefinitionParser $services_definition_parser,
        ConfigurationFilesProviderInterface $config_files_provider,
        ?ModuleDependencyValidator $module_dependency_validator = null
    ) {
        $this->cache = $cache;
        $this->object_builder = $object_builder;
        $this->services_definition_parser = $services_definition_parser;
        $this->config_files_provider = $config_files_provider;
        $this->module_dependency_validator = $module_dependency_validator;
    }

    /**
     * Create container instance with automatic service discovery
     *
     * @param bool $useConfigProvider Whether to use config provider for automatic discovery
     * @param string $fallbackFile Fallback service file if config provider not available
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    public function getInstance(): Container
    {
        if ($this->module_dependency_validator !== null) {
            $this->module_dependency_validator->validate();
        }

        $configurationValidator = new ConfigurationValidator();
        foreach ($this->config_files_provider->getAllConfigurationFiles() as $type => $files) {
            foreach ($files as $file) {
                $configurationValidator->validateFile($type, $file);
            }
        }

        $container = new Container($this->cache, $this->object_builder);

        // Use configuration files provider to discover all service files
        $serviceFiles = $this->config_files_provider->getConfigurationFiles(ConfigurationType::services());

        $definitions = [];
        $sources = [];
        foreach ($serviceFiles as $file) {
            $services = $this->services_definition_parser->parse($file);
            foreach ($services as $name => $service) {
                if (in_array($name, ['cache', 'factory', 'container', 'Psr\\Container\\ContainerInterface'], true)) {
                    throw new \RuntimeException(sprintf(
                        'Service ID "%s" in %s is reserved by the container and cannot be registered.',
                        $name,
                        $file
                    ));
                }
                if (isset($definitions[$name])) {
                    throw new \RuntimeException(sprintf(
                        'Service ID "%s" is defined in both %s and %s.',
                        $name,
                        $sources[$name],
                        $file
                    ));
                }
                $definitions[$name] = $service;
                $sources[$name] = $file;
            }
        }

        $this->applyDecorators($definitions, $sources);

        foreach ($definitions as $name => $service) {
            $container->set($name, $service);
        }

        return $container;
    }

    /**
     * Resolve explicit service decoration into ordinary container definitions.
     */
    private function applyDecorators(array &$definitions, array &$sources): void
    {
        $decoratorsByTarget = [];
        foreach ($definitions as $decoratorId => $definition) {
            if (!is_array($definition) || !isset($definition['decorates'])) {
                continue;
            }

            $target = $definition['decorates'];
            if (!isset($definitions[$target])) {
                throw new \RuntimeException(sprintf(
                    'Service decorator "%s" in %s targets undefined service "%s".',
                    $decoratorId,
                    $sources[$decoratorId],
                    $target
                ));
            }
            if ($decoratorId === $target) {
                throw new \RuntimeException(sprintf('Service "%s" cannot decorate itself (%s).', $decoratorId, $sources[$decoratorId]));
            }
            if (isset($decoratorsByTarget[$target])) {
                throw new \RuntimeException(sprintf(
                    'Service "%s" has multiple decorators: "%s" in %s and "%s" in %s. Decorators must form an explicit chain.',
                    $target,
                    $decoratorsByTarget[$target],
                    $sources[$decoratorsByTarget[$target]],
                    $decoratorId,
                    $sources[$decoratorId]
                ));
            }

            $decoratorsByTarget[$target] = $decoratorId;
        }

        $applied = [];
        $applying = [];
        $visitedTargets = [];
        $visitTarget = function (string $target) use (&$visitTarget, &$visitedTargets, &$decoratorsByTarget, &$sources): void {
            if (($visitedTargets[$target] ?? null) === 'visiting') {
                $decoratorId = $decoratorsByTarget[$target] ?? $target;
                throw new \RuntimeException(sprintf('Circular service decoration detected at "%s" (%s).', $decoratorId, $sources[$decoratorId] ?? 'unknown source'));
            }
            if (isset($visitedTargets[$target])) {
                return;
            }
            if (!isset($decoratorsByTarget[$target])) {
                $visitedTargets[$target] = true;
                return;
            }
            $decoratorId = $decoratorsByTarget[$target];
            $visitedTargets[$target] = 'visiting';
            if (($visitedTargets[$decoratorId] ?? null) === 'visiting') {
                throw new \RuntimeException(sprintf('Circular service decoration detected at "%s" (%s).', $decoratorId, $sources[$decoratorId]));
            }
            $visitTarget($decoratorId);
            $visitedTargets[$target] = true;
        };
        foreach (array_keys($decoratorsByTarget) as $target) {
            $visitTarget($target);
        }

        $applyTarget = function (string $target) use (&$applyTarget, &$definitions, &$sources, &$decoratorsByTarget, &$applied, &$applying): void {
            if (!isset($decoratorsByTarget[$target])) {
                return;
            }
            $decoratorId = $decoratorsByTarget[$target];
            if (isset($applied[$decoratorId])) {
                return;
            }
            if (isset($applying[$decoratorId])) {
                throw new \RuntimeException(sprintf('Circular service decoration detected at "%s" (%s).', $decoratorId, $sources[$decoratorId]));
            }
            $applying[$decoratorId] = true;

            $definition = $definitions[$decoratorId];
            unset($definition['decorates']);
            $strategy = isset($definition['class']) ? 'class' : 'factory';
            $arguments = $definition[$strategy]['arguments'] ?? [];
            $matches = 0;
            foreach ($arguments as $index => $argument) {
                if ($argument === '@.inner') {
                    ++$matches;
                    $arguments[$index] = '@'.$this->getInnerServiceId($decoratorId);
                }
            }
            if ($matches !== 1) {
                throw new \RuntimeException(sprintf(
                    'Service decorator "%s" in %s must use the "@.inner" argument exactly once.',
                    $decoratorId,
                    $sources[$decoratorId]
                ));
            }

            $innerId = $this->getInnerServiceId($decoratorId);
            if (isset($definitions[$innerId])) {
                throw new \RuntimeException(sprintf('Reserved inner service ID collision for "%s" (%s).', $innerId, $sources[$decoratorId]));
            }
            $definitions[$innerId] = $definitions[$target];
            $sources[$innerId] = $sources[$target];
            $definition[$strategy]['arguments'] = $arguments;
            $definitions[$decoratorId] = $definition;
            $definitions[$target] = $decoratorId;
            $sources[$target] = $sources[$decoratorId];

            unset($applying[$decoratorId]);
            $applied[$decoratorId] = true;

            // Build an explicit chain from the target outward, preserving each inner definition.
            $applyTarget($decoratorId);
        };

        foreach (array_keys($decoratorsByTarget) as $target) {
            $applyTarget($target);
        }
    }

    private function getInnerServiceId(string $decoratorId): string
    {
        return 'flexi.decorator.inner.'.hash('sha256', $decoratorId);
    }

    public static function createDefault(
    ): Container {
        $configurationRepository = new ConfigurationRepository();
        $configuration = new Configuration($configurationRepository);
        $modulesStateManager = new ModuleStateManager(new ModuleStateRepository("./var/modules-state.json"));
        $moduleCacheManager = new ModuleCacheManager();
        $moduleDetector = new HybridModuleDetector(
            new LocalModuleDetector($moduleCacheManager),
            new VendorModuleDetector($moduleCacheManager)
        );
        $cache = (new DefaultCacheFactory($moduleDetector, $configuration))->createCache();
        $objectBuilder = new ObjectBuilder($cache);
        $servicesDefinitionParser = new ServicesDefinitionParser($cache);
        $configFilesProvider = new ConfigurationFilesProvider($modulesStateManager, $moduleDetector);
        $rootComposerFile = './composer.json';
        $rootComposerData = is_file($rootComposerFile)
            ? json_decode((string) file_get_contents($rootComposerFile), true)
            : null;
        $coreVersion = is_array($rootComposerData) && isset($rootComposerData['version'])
            ? (string) $rootComposerData['version']
            : '0.0.0';
        $moduleDependencyValidator = new ModuleDependencyValidator(
            $moduleDetector,
            $modulesStateManager,
            './',
            $coreVersion
        );

        return (new self(
            $cache,
            $objectBuilder,
            $servicesDefinitionParser,
            $configFilesProvider,
            $moduleDependencyValidator
        ))->getInstance();
    }
}
