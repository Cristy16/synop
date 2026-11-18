<?php

namespace App\Enums;

/**
 * How a schedule rule repeats (schedule_rules.frequency, D-042).
 */
enum Frequency: string
{
    case Daily = 'daily';
    case EveryNDays = 'every_n_days';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * The one extra field this frequency uses, or null for daily.
     */
    public function field(): ?string
    {
        return match ($this) {
            self::Daily => null,
            self::EveryNDays => 'interval',
            self::Weekly => 'weekdays',
            self::Monthly => 'day_of_month',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::EveryNDays => 'Every few days',
            self::Weekly => 'On certain weekdays',
            self::Monthly => 'Once a month',
        };
    }
}
