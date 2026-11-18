<?php

namespace App\Enums;

/**
 * wants.status.
 */
enum WantStatus: string
{
    case Wanted = 'wanted';
    case Bought = 'bought';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Wanted => 'Wanted',
            self::Bought => 'Bought',
            self::Dropped => 'Dropped',
        };
    }
}
