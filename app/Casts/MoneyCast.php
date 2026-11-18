<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Reads a `*_cents` integer column as Money and writes it back as an integer.
 *
 * Strings and floats are rejected: parse user input with Money::parse() first.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        // Some database drivers return BIGINT as a numeric string.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException("Column {$key} does not hold whole centavos.");
        }

        return Money::fromCents($value);
    }

    /**
     * Eloquent passes whatever was assigned, so $value is checked at runtime.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->cents(),
            is_int($value) => $value,
            default => throw new InvalidArgumentException(
                "{$key} must be Money or integer centavos; parse strings with Money::parse()."
            ),
        };
    }
}
