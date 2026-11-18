<?php

namespace App\Actions\Education;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SemesterInput
{
    /**
     * @param  array<string, mixed>  $input  name, starts_on, ends_on (Y-m-d)
     * @return array{name: string, starts_on: string, ends_on: string}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ])->validate();

        return ['name' => trim($validated['name']), 'starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on']];
    }
}
