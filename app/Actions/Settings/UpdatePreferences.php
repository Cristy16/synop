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

    public const MAX_PAYDAYS = 4;

    /**
     * Validate and save the user's app preferences.
     *
     * @param  array<string, mixed>  $input  timezone, currency, safety_buffer (string), payments_due_days,
     *                                       paydays (optional text, e.g. "15, 30"; empty = not set)
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

        if (array_key_exists('paydays', $input)) {
            $user->paydays = $this->parsePaydays((string) ($input['paydays'] ?? ''));
        }

        $user->save();
    }

    /**
     * "15, 30" → [15, 30]. Days 1–31 (29–31 fall on the last day of shorter months).
     *
     * @return list<int>|null
     *
     * @throws ValidationException
     */
    private function parsePaydays(string $input): ?array
    {
        $parts = array_filter(array_map('trim', preg_split('/[,\s]+/', $input) ?: []), fn (string $p) => $p !== '');

        if ($parts === []) {
            return null;
        }

        $days = [];

        foreach ($parts as $part) {
            if (preg_match('/^\d{1,2}$/', $part) !== 1 || (int) $part < 1 || (int) $part > 31) {
                throw ValidationException::withMessages(['paydays' => __('Use days of the month from 1 to 31, like 15, 30.')]);
            }

            $days[] = (int) $part;
        }

        $days = array_values(array_unique($days));
        sort($days);

        if (count($days) > self::MAX_PAYDAYS) {
            throw ValidationException::withMessages(['paydays' => __('Up to :max paydays.', ['max' => self::MAX_PAYDAYS])]);
        }

        return $days;
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
