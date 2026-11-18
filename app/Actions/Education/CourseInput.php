<?php

namespace App\Actions\Education;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseInput
{
    /**
     * @param  array<string, mixed>  $input  semester_id, code, title, instructor, room
     * @return array{semester_id: int, code: string|null, title: string, instructor: string|null, room: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            // The user's own semester (rule 10).
            'semester_id' => ['required', 'integer', Rule::exists('semesters', 'id')->where('user_id', $user->id)],
            'code' => ['nullable', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:150'],
            'instructor' => ['nullable', 'string', 'max:100'],
            'room' => ['nullable', 'string', 'max:50'],
        ])->validate();

        $blank = fn (?string $v) => $v === null || trim($v) === '' ? null : trim($v);

        return [
            'semester_id' => (int) $validated['semester_id'],
            'code' => $blank($validated['code'] ?? null),
            'title' => trim($validated['title']),
            'instructor' => $blank($validated['instructor'] ?? null),
            'room' => $blank($validated['room'] ?? null),
        ];
    }
}
