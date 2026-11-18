<?php

namespace App\Actions\Finance;

use App\Support\Money;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns an amount typed as text ("1,500.50") into Money, with integer maths only (rule 13).
 */
trait ParsesMoney
{
    /**
     * @param  'positive'|'not_negative'  $sign
     *
     * @throws ValidationException
     */
    protected function parseMoney(string $input, string $field, string $sign): Money
    {
        try {
            $money = Money::parse($input);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field => __('Enter an amount like 1,500.00.'),
            ]);
        }

        if ($sign === 'positive' && ($money->isNegative() || $money->isZero())) {
            throw ValidationException::withMessages([$field => __('The amount must be more than zero.')]);
        }

        if ($sign === 'not_negative' && $money->isNegative()) {
            throw ValidationException::withMessages([$field => __('The amount cannot be negative.')]);
        }

        return $money;
    }
}
