<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ReopenTask
{
    /**
     * Mark a completed task as not done.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Task $task): Task
    {
        Gate::forUser($user)->authorize('complete', $task);

        if ($task->isCompleted()) {
            $task->completed_at = null;
            $task->save();
        }

        return $task;
    }
}
