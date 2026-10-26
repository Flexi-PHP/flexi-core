<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Services;

use Flexi\Application\Services\ComposerProcessInterface;

final class NativeComposerProcess implements ComposerProcessInterface
{
    public function run(array $arguments, string $workingDirectory): array
    {
        $command = array_merge([getenv('COMPOSER_BINARY') ?: 'composer'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $workingDirectory);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start Composer process.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => trim($output)];
    }
}
