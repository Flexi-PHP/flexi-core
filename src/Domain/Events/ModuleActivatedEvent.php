<?php

declare(strict_types=1);

namespace Flexi\Domain\Events;

use Flexi\Domain\ValueObjects\ModuleInfo;

final class ModuleActivatedEvent extends Event implements SynchronousEventInterface
{
    public const NAME = 'core.moduleActivated';

    public function __construct(ModuleInfo $module, string $firedBy)
    {
        parent::__construct(self::NAME, $firedBy, self::moduleData($module));
    }

    private static function moduleData(ModuleInfo $module): array
    {
        return [
            'module_name' => $module->getName(),
            'package' => $module->getPackage(),
            'type' => $module->getType()->getValue(),
            'path' => $module->getPath(),
        ];
    }
}
