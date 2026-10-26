<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Ui\Cli;

use Flexi\Infrastructure\Bus\CommandBus;
use Flexi\Infrastructure\Bus\EventBus;
use Flexi\Infrastructure\Bus\QueryBus;
use Flexi\Infrastructure\Classes\Configuration;
use Flexi\Infrastructure\Classes\ConfigurationRepository;
use Flexi\Infrastructure\Factories\ContainerFactory;
use Flexi\Infrastructure\ErrorHandling\ErrorHandlerCall;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\ErrorHandler\Debug;

class ConsoleApplication
{
    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws \ReflectionException
     * @throws \ErrorException
     * @throws \JsonException
     * @throws \Exception
     */
    public static function run($argv): void
    {
        $configRepo = new ConfigurationRepository();
        $config = new Configuration($configRepo);

        $debugMode = 'true' === $config->get('DEBUG_MODE');

        if ($debugMode) {
            Debug::enable();
        }

        // Suppress deprecation warnings about return types in production
        error_reporting(E_ALL & ~E_USER_DEPRECATED);

        try {
            echo ErrorHandlerCall::run(static function () use ($argv, $debugMode) {
                return self::handle( $argv, $debugMode);
            });
        } catch (\Throwable $e) {
            echo ConsoleExceptionFormatter::format($e, $debugMode);
            exit(1);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws \JsonException
     * @throws NotFoundExceptionInterface
     * @throws \ReflectionException
     */
    private static function handle(array $argv, bool $debugMode): string
    {
        $container = ContainerFactory::createDefault();

        try {
            $input = CliInputParser::parse($argv);
        } catch (\Exception $e) {
            return ConsoleExceptionFormatter::format($e, $debugMode);
        }

        try {
            if (CliType::COMMAND === $input->getType()) {
                $result = (new CommandHandler($container->get(CommandBus::class)))->handle($input);
            } elseif (CliType::QUERY === $input->getType()) {
                $result = (new QueryHandler($container->get(QueryBus::class)))->handle($input);
            } else {
                $result = (new EventHandler($container->get(EventBus::class)))->handle($input);
            }

            return ConsoleOutputFormatter::format($result);
        } catch (\Exception $e) {
            return ConsoleExceptionFormatter::format($e, $debugMode);
        }
    }

    public static function printUsage(): void
    {
        echo '- Usage: '.ConsoleOutputFormatter::format(
            '--command(-c)|--query(-q)|--event(-e)',
            'green',
            false
        ).' command_name|query_name|event_name '.ConsoleOutputFormatter::format('arg1=blabla arg2=blabla', 'green');
        echo '- Try '.ConsoleOutputFormatter::format(
            'command:list',
            'green',
            false
        ).' or '.ConsoleOutputFormatter::format(
            'query:list',
            'green',
            false
        ).' for a list of options'.PHP_EOL;
        echo '- Type command with '.ConsoleOutputFormatter::format(
            '--help(-h)',
            'green',
            false
        ).' option for usage'.PHP_EOL;
    }
}
