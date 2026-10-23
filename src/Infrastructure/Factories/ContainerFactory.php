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
use Flexi\Infrastructure\Classes\VendorModuleDetector;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class ContainerFactory
{
    private CacheInterface $cache;
    private ObjectBuilderInterface $object_builder;
    private ServicesDefinitionParser $services_definition_parser;
    private ConfigurationFilesProviderInterface $config_files_provider;

    public function __construct(
        CacheInterface $cache,
        ObjectBuilderInterface $object_builder,
        ServicesDefinitionParser $services_definition_parser,
        ConfigurationFilesProviderInterface $config_files_provider
    ) {
        $this->cache = $cache;
        $this->object_builder = $object_builder;
        $this->services_definition_parser = $services_definition_parser;
        $this->config_files_provider = $config_files_provider;
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
        $configurationValidator = new ConfigurationValidator();
        foreach ($this->config_files_provider->getAllConfigurationFiles() as $type => $files) {
            foreach ($files as $file) {
                $configurationValidator->validateFile($type, $file);
            }
        }

        $container = new Container($this->cache, $this->object_builder);

        // Use configuration files provider to discover all service files
        $serviceFiles = $this->config_files_provider->getConfigurationFiles(ConfigurationType::services());

        foreach ($serviceFiles as $file) {
            $services = $this->services_definition_parser->parse($file);
            foreach ($services as $name => $service) {
                $container->set($name, $service);
            }
        }

        return $container;
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

        return (new self($cache, $objectBuilder, $servicesDefinitionParser, $configFilesProvider))->getInstance();
    }
}
