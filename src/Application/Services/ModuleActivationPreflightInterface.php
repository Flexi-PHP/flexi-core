<?php

declare(strict_types=1);

namespace Flexi\Application\Services;

use Flexi\Domain\ValueObjects\ModuleInfo;

interface ModuleActivationPreflightInterface
{
    public function validate(ModuleInfo $module): void;
}
