<?php

namespace App\Enums;

/**
 * conflict_rules.scope: where two ingredient groups shouldn't meet.
 */
enum ConflictScope: string
{
    /** Both in the same routine (e.g. tonight's PM routine). */
    case SameRoutine = 'same_routine';

    /** Both on the same day, AM or PM. */
    case SameDay = 'same_day';

    public function label(): string
    {
        return match ($this) {
            self::SameRoutine => 'In the same routine',
            self::SameDay => 'On the same day',
        };
    }
}
