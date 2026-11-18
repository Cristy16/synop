<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateTask
{
    /**
     * Create a task for the user. Other modules pass the record that owns the
     * task as `$source` (e.g. an activity or a debt); the user never picks it.
     *
     * @param  array<string, mixed>  $input  title, notes, due_on (Y-m-d), due_time (H:i), priority
     *
     * @throws ValidationException
     * @throws LogicException when the source belongs to another user or already has a task
     */
    public function handle(User $user, array $input, ?Model $source = null): Task
    {
        $validated = Validator::make($input, TaskRules::rules())->validate();

        $task = new Task([
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'due_time' => $validated['due_time'] ?? null,
            'priority' => $validated['priority'] ?? TaskPriority::Normal->value,
        ]);
        $task->user_id = $user->id;

        if ($source !== null) {
            $this->linkSource($user, $task, $source);
        }

        $task->save();

        return $task;
    }

    private function linkSource(User $user, Task $task, Model $source): void
    {
        // A record must never link to another user's record (rule 10).
        if ((int) $source->getAttribute('user_id') !== $user->id) {
            throw new LogicException('A task can only be linked to a source owned by the same user.');
        }

        // getMorphClass() throws if the source's model is missing from the morph map.
        $type = $source->getMorphClass();
        $id = (int) $source->getKey();

        if (Task::forUser($user)->where('source_type', $type)->where('source_id', $id)->exists()) {
            throw new LogicException("This {$type} already has a task.");
        }

        $task->source_type = $type;
        $task->source_id = $id;
    }
}
