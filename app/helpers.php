<?php

use App\Enums\Currency;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

if (! function_exists('money')) {
    /**
     * Format Money or integer centavos for display: money(123450) → "₱1,234.50".
     *
     * Uses the logged-in user's currency unless one is given (e.g. in a
     * notification, pass $user->currency). Falls back to PHP.
     */
    function money(Money|int|null $amount, ?Currency $currency = null): string
    {
        if ($amount === null) {
            return '';
        }

        if ($currency === null) {
            $user = Auth::user();
            $currency = $user instanceof User ? $user->currency : Currency::PHP;
        }

        return ($amount instanceof Money ? $amount : Money::fromCents($amount))->format($currency);
    }
}
