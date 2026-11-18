<?php

namespace App\Enums;

/**
 * routines.period: one morning and one night routine per user (D-017).
 */
enum RoutinePeriod: string
{
    case Am = 'am';
    case Pm = 'pm';

    public function label(): string
    {
        return match ($this) {
            self::Am => 'AM',
            self::Pm => 'PM',
        };
    }

    public function defaultName(): string
    {
        return match ($this) {
            self::Am => 'Morning',
            self::Pm => 'Night',
        };
    }
}
