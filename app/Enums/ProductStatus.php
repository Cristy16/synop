<?php

namespace App\Enums;

/**
 * products.status. Finished and discarded are states, not deletions (DATABASE §1.4).
 */
enum ProductStatus: string
{
    case Unopened = 'unopened';
    case InUse = 'in_use';
    case Finished = 'finished';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Unopened => 'Unopened',
            self::InUse => 'In use',
            self::Finished => 'Finished',
            self::Discarded => 'Discarded',
        };
    }

    /**
     * Still on the shelf: counts for "expiring soon".
     */
    public function isActive(): bool
    {
        return $this === self::Unopened || $this === self::InUse;
    }
}
