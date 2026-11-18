<?php

namespace App\Actions\Education;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClassSessionInput
{
    /**
     * @param  array<string, mixed>  $input  start_time, end_time (H:i), weekdays (ISO, Mon = 1), room, label
     * @return array{start_time: string, end_time: string, weekdays: list<int>, room: string|null, label: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $validated = Validator::make($input, [
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'room' => ['nullable', 'string', 'max:50'],
            'label' => ['nullable', 'string', 'max:50'],
        ], [
            'end_time.after' => __('The class has to end after it starts.'),
            'weekdays.required' => __('Choose at least one day.'),
        ])->validate();

        $weekdays = array_values(array_unique(array_map('intval', $validated['weekdays'])));
        sort($weekdays);
        $blank = fn (?string $v) => $v === null || trim($v) === '' ? null : trim($v);

        return [
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'weekdays' => $weekdays,
            'room' => $blank($validated['room'] ?? null),
            'label' => $blank($validated['label'] ?? null),
        ];
    }
}
