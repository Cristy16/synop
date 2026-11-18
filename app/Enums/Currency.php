<?php

namespace App\Enums;

/**
 * The currency a user's amounts are shown in (users.currency).
 *
 * Display only: changing it swaps the symbol, it never converts amounts.
 * Only currencies with 2 decimal places, because Money stores hundredths.
 */
enum Currency: string
{
    case PHP = 'PHP';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case SGD = 'SGD';
    case AUD = 'AUD';
    case CAD = 'CAD';
    case HKD = 'HKD';

    public function symbol(): string
    {
        return match ($this) {
            self::PHP => '₱',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::SGD => 'S$',
            self::AUD => 'A$',
            self::CAD => 'C$',
            self::HKD => 'HK$',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PHP => 'Philippine peso',
            self::USD => 'US dollar',
            self::EUR => 'Euro',
            self::GBP => 'British pound',
            self::SGD => 'Singapore dollar',
            self::AUD => 'Australian dollar',
            self::CAD => 'Canadian dollar',
            self::HKD => 'Hong Kong dollar',
        };
    }
}
