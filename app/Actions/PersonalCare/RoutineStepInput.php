<?php

namespace App\Actions\PersonalCare;

use App\Enums\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation for a routine step's own fields (its schedule is checked by the
 * schedule Actions).
 */
class RoutineStepInput
{
    /**
     * @param  array<string, mixed>  $input  product_id, note
     * @return array{product_id: int, note: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            // The user's own skincare or makeup product; vitamins get their own doses (D-046).
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('user_id', $user->id)
                ->whereIn('type', [ProductType::Skincare->value, ProductType::Makeup->value])
                ->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'product_id.exists' => __('Choose one of your skincare or makeup products.'),
        ])->validate();

        return [
            'product_id' => (int) $validated['product_id'],
            'note' => isset($validated['note']) && trim($validated['note']) !== '' ? trim($validated['note']) : null,
        ];
    }

    /**
     * The schedule part of the input, named as the schedule Actions expect.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function schedule(array $input): array
    {
        return [
            'frequency' => $input['frequency'] ?? 'daily',
            'interval' => $input['interval'] ?? null,
            'weekdays' => $input['weekdays'] ?? null,
            'day_of_month' => $input['day_of_month'] ?? null,
        ];
    }
}
