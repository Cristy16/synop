<?php

namespace App\Actions\Education;

use App\Enums\ActivityType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActivityInput
{
    /**
     * @param  array<string, mixed>  $input  course_id, type, title, due_on (Y-m-d), due_time (H:i), notes
     * @return array{course_id: int, type: ActivityType, title: string, due_on: string, due_time: string|null, notes: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')->where('user_id', $user->id)],
            'type' => ['required', Rule::enum(ActivityType::class)],
            'title' => ['required', 'string', 'max:255'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return [
            'course_id' => (int) $validated['course_id'],
            'type' => ActivityType::from($validated['type']),
            'title' => trim($validated['title']),
            'due_on' => $validated['due_on'],
            'due_time' => $validated['due_time'] ?? null,
            'notes' => isset($validated['notes']) && trim($validated['notes']) !== '' ? trim($validated['notes']) : null,
        ];
    }
}
