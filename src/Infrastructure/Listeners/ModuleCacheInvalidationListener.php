<?php

declare(strict_types=1);

namespace Flexi\Infrastructure\Listeners;

use Flexi\Domain\Events\ModuleActivatedEvent;
use Flexi\Domain\Events\ModuleDeactivatedEvent;
use Flexi\Domain\Interfaces\ModuleCacheManagerInterface;
use Flexi\Contracts\Interfaces\EventInterface;
use Flexi\Contracts\Interfaces\EventListenerInterface;
use Flexi\Infrastructure\Classes\LocalModuleDetector;
use Flexi\Infrastructure\Classes\VendorModuleDetector;

/** Clears module discovery caches after an activation state transition. */
final class ModuleCacheInvalidationListener implements EventListenerInterface
{
    private ModuleCacheManagerInterface $moduleCacheManager;

    public function __construct(ModuleCacheManagerInterface $moduleCacheManager)
    {
        $this->moduleCacheManager = $moduleCacheManager;
    }

    public function handle(EventInterface $event): void
    {
        if (!in_array($event->getName(), [ModuleActivatedEvent::NAME, ModuleDeactivatedEvent::NAME], true)) {
            return;
        }

        if (!$this->moduleCacheManager->invalidateCache()) {
            throw new \RuntimeException('Unable to invalidate the Flexi module discovery cache.');
        }
        LocalModuleDetector::clearCache();
        VendorModuleDetector::clearCache();
    }
}
