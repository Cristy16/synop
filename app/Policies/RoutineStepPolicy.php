<?php

namespace App\Policies;

use App\Models\RoutineStep;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (no duplicates, own products only…) live in the
 * Personal Care Actions, which also check the input (D-046).
 */
class RoutineStepPolicy
{
    public function view(User $user, RoutineStep $step): Response
    {
        return $this->owns($user, $step);
    }

    public function update(User $user, RoutineStep $step): Response
    {
        return $this->owns($user, $step);
    }

    public function delete(User $user, RoutineStep $step): Response
    {
        return $this->owns($user, $step);
    }

    public function restore(User $user, RoutineStep $step): Response
    {
        return $this->owns($user, $step);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, RoutineStep $step): Response
    {
        return $user->id === $step->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
