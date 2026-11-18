<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class CompleteTask
{
    /**
     * Mark a one-off task done. Completing it again keeps the first completion time.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Task $task): Task
    {
        Gate::forUser($user)->authorize('complete', $task);

        if (! $task->isCompleted()) {
            $task->completed_at = now(); // stored in UTC (rule 3)
            $task->save();
        }

        return $task;
    }
}
