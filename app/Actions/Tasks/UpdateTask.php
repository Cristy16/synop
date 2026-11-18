<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTask
{
    /**
     * Save the task's form fields. The source and completion are not changed here.
     *
     * @param  array<string, mixed>  $input  title, notes, due_on (Y-m-d), due_time (H:i), priority
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Task $task, array $input): Task
    {
        Gate::forUser($user)->authorize('update', $task);

        $validated = Validator::make($input, TaskRules::rules())->validate();

        $task->fill([
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'due_time' => $validated['due_time'] ?? null,
            'priority' => $validated['priority'] ?? $task->priority,
        ])->save();

        return $task;
    }
}
