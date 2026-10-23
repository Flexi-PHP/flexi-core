<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Ui\Cli;

class CliInputParser
{
    public const FORMAT = '/^(--command|--query|--event|-c|-q|-e)\s+[a-zA-Z0-9:_-]+(\s+[a-zA-Z0-9_-]+=[^\s]*)*(\s+(--help|-h))?$/D';

    public static function parse(array $input): CliInput
    {
        array_shift($input); // clear entry point script
        self::assertIsValidInput($input);
        $command_type = array_shift($input);
        $commandName = array_shift($input);
        $arguments = [];
        $show_help = false;
        $type = CliType::QUERY;

        if ('--command' === $command_type || '-c' === $command_type) {
            $type = CliType::COMMAND;
        } elseif ('--event' === $command_type || '-e' === $command_type) {
            $type = CliType::EVENT;
        }

        foreach ($input as $arg) {
            if (0 === strpos($arg, '--help') || 0 === strpos($arg, '-h')) {
                $show_help = true;
                continue;
            }

            [$optionName, $optionValue] = explode('=', $arg, 2);
            $arguments[$optionName] = $optionValue ?? false;
        }

        return new CliInput($commandName, $arguments, $type, $show_help);
    }

    private static function assertIsValidInput(array $input): void
    {
        // regex to match --command|--query or -c|-q followed by a command name and arguments in the form key=value with optional --help or -h
        if (!preg_match(self::FORMAT, implode(' ', $input))) {
            throw new \InvalidArgumentException('Invalid input format');
        }
    }
}
