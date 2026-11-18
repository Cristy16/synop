<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskPolicy
{
    public function view(User $user, Task $task): Response
    {
        return $this->owns($user, $task);
    }

    public function update(User $user, Task $task): Response
    {
        return $this->owns($user, $task);
    }

    /**
     * Marking done / reopening.
     */
    public function complete(User $user, Task $task): Response
    {
        return $this->owns($user, $task);
    }

    public function delete(User $user, Task $task): Response
    {
        $owns = $this->owns($user, $task);

        if ($owns->denied()) {
            return $owns;
        }

        // A deadline task would silently disappear while its activity/debt still exists (D-012).
        if ($task->isSourceProtected()) {
            return Response::deny(__('This task belongs to a :source. Delete the :source instead.', [
                'source' => $task->source_type,
            ]));
        }

        return Response::allow();
    }

    /**
     * Another user's task answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Task $task): Response
    {
        return $user->id === $task->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
