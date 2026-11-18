<?php

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteTask
{
    /**
     * Delete a task for good (tasks are not soft-deleted, DATABASE §1.4).
     * Tasks created by an activity or a debt are refused by the Policy.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Task $task): void
    {
        Gate::forUser($user)->authorize('delete', $task);

        $task->delete();
    }
}
