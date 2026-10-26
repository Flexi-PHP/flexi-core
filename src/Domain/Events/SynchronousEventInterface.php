<?php

declare(strict_types=1);

namespace Flexi\Domain\Events;

use Flexi\Contracts\Interfaces\EventInterface;

/** Marks events whose listeners must finish before the dispatching operation returns. */
interface SynchronousEventInterface extends EventInterface
{
}
