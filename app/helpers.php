<?php

use App\Support\Money;

if (! function_exists('money')) {
    /**
     * Format Money or integer centavos for display: money(123450) → "₱1,234.50".
     */
    function money(Money|int|null $amount): string
    {
        if ($amount === null) {
            return '';
        }

        return ($amount instanceof Money ? $amount : Money::fromCents($amount))->format();
    }
}
