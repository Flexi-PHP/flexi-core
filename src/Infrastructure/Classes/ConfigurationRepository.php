<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Classes;

use Flexi\Contracts\Classes\Collection;
use Flexi\Contracts\Interfaces\ConfigurationRepositoryInterface;
use Flexi\Contracts\ValueObjects\CollectionType;
use Dotenv\Dotenv;

class ConfigurationRepository implements ConfigurationRepositoryInterface
{
    private Collection $config;

    public function __construct()
    {
        $this->config = new Collection(new CollectionType('string'));
        $this->init();
    }

    private function init(): void
    {
        $root_dir = dirname(__DIR__, 3);
        $dotenv = Dotenv::createUnsafeImmutable($root_dir);
        $dotenv->safeLoad();
        foreach ($_ENV as $key => $value) {
            $this->config->add($value, $key);
        }
        $this->config->add($root_dir, 'ROOT_DIR');
        $this->config->add($root_dir.'/src', 'APP_DIR');
        $this->config->add($root_dir.'/modules', 'MODULES_DIR');
        $debug = getenv('debug');
        $this->config->add($debug, 'DEBUG_MODE');
    }

    /**
     * Summary of get.
     */
    public function get(string $key)
    {
        return $this->config->get($key);
    }

    public function has(string $key): bool
    {
        return $this->config->has($key);
    }

    public function getAll(): array
    {
        return $this->config->getArrayCopy();
    }
}
