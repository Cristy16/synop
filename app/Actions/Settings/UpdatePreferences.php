<?php

namespace App\Actions\Settings;

use App\Enums\Currency;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdatePreferences
{
    public const MIN_PAYMENTS_DUE_DAYS = 1;

    public const MAX_PAYMENTS_DUE_DAYS = 30;

    /**
     * Validate and save the user's app preferences.
     *
     * @param  array<string, mixed>  $input  timezone, currency, safety_buffer (string), payments_due_days
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'timezone' => ['required', 'string', 'timezone:all'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'safety_buffer' => ['required', 'string', 'max:30'],
            'payments_due_days' => [
                'required', 'integer', 'between:'.self::MIN_PAYMENTS_DUE_DAYS.','.self::MAX_PAYMENTS_DUE_DAYS,
            ],
        ])->validate();

        $user->timezone = $validated['timezone'];
        $user->currency = Currency::from($validated['currency']);
        $user->safety_buffer_cents = $this->parseSafetyBuffer($validated['safety_buffer']);
        $user->payments_due_days = (int) $validated['payments_due_days'];
        $user->save();
    }

    /**
     * @throws ValidationException
     */
    private function parseSafetyBuffer(string $input): Money
    {
        try {
            $buffer = Money::parse($input);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'safety_buffer' => __('Enter an amount like 1,500.00.'),
            ]);
        }

        if ($buffer->isNegative()) {
            throw ValidationException::withMessages([
                'safety_buffer' => __('The safety buffer cannot be negative.'),
            ]);
        }

        return $buffer;
    }
}
