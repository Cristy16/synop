<?php

namespace App\Actions\PersonalCare;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation shared by CreateIngredientTag and UpdateIngredientTag.
 */
class IngredientTagInput
{
    public const MAX_KEYWORDS = 50;

    /**
     * @param  array<string, mixed>  $input  name, keywords (text: one per line or comma-separated)
     * @return array{name: string, keywords: list<string>}
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input, ?int $ignoreId = null): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:50', Rule::unique('ingredient_tags', 'name')->where('user_id', $user->id)->ignore($ignoreId)],
            'keywords' => ['required', 'string', 'max:3000'],
        ], [
            'name.unique' => __('You already have a group with this name.'),
        ])->validate();

        // "Glycolic acid, Lactic acid" or one per line → ["glycolic acid", "lactic acid"].
        $keywords = array_values(array_unique(array_filter(array_map(
            fn (string $k) => mb_strtolower(trim($k)),
            preg_split('/[,\n]+/', (string) $validated['keywords']) ?: [],
        ), fn (string $k) => $k !== '')));

        if ($keywords === []) {
            throw ValidationException::withMessages(['keywords' => __('Add at least one ingredient name.')]);
        }

        if (count($keywords) > self::MAX_KEYWORDS) {
            throw ValidationException::withMessages(['keywords' => __('Keep it to :max ingredient names.', ['max' => self::MAX_KEYWORDS])]);
        }

        foreach ($keywords as $keyword) {
            if (mb_strlen($keyword) > 60) {
                throw ValidationException::withMessages(['keywords' => __('":keyword" is too long.', ['keyword' => $keyword])]);
            }
        }

        return ['name' => trim($validated['name']), 'keywords' => $keywords];
    }
}
