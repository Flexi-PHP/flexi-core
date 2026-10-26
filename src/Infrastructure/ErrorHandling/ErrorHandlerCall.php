<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\ErrorHandling;

/** Runs a callback while converting reportable PHP errors into exceptions. */
final class ErrorHandlerCall
{
    public static function run(callable $callback)
    {
        set_error_handler(static function (int $type, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $type)) {
                return false;
            }

            throw new \ErrorException($message, 0, $type, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
