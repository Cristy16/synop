<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use Illuminate\Validation\Rule;

/**
 * Validation rules shared by CreateTask and UpdateTask.
 */
class TaskRules
{
    public const MAX_NOTES = 5000;

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES],
            // Dates and times exactly as the user means them, never converted (rule 9).
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ];
    }
}
