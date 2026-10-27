<?php

declare(strict_types=1);

namespace Flexi\Application\Services;

interface ComposerProcessInterface
{
    /** @return array{exit_code:int, output:string} */
    public function run(array $arguments, string $workingDirectory): array;
}
