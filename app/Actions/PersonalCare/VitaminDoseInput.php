<?php

namespace App\Actions\PersonalCare;

use App\Enums\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation for a vitamin dose's own fields (its schedule is checked by the
 * schedule Actions).
 */
class VitaminDoseInput
{
    /**
     * @param  array<string, mixed>  $input  product_id, dose_time (H:i), units, meal_note
     * @return array{product_id: int, dose_time: string, units: int, meal_note: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            // The user's own vitamin (rule 10).
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')
                ->where('user_id', $user->id)
                ->where('type', ProductType::Vitamin->value)
                ->whereNull('deleted_at')],
            'dose_time' => ['required', 'date_format:H:i'],
            'units' => ['nullable', 'integer', 'between:1,20'],
            'meal_note' => ['nullable', 'string', 'max:100'],
        ], [
            'product_id.exists' => __('Choose one of your vitamins.'),
        ])->validate();

        return [
            'product_id' => (int) $validated['product_id'],
            'dose_time' => $validated['dose_time'],
            'units' => isset($validated['units']) ? (int) $validated['units'] : 1,
            'meal_note' => isset($validated['meal_note']) && trim($validated['meal_note']) !== '' ? trim($validated['meal_note']) : null,
        ];
    }
}
