<?php

namespace App\Policies;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (no duplicates, own products only…) live in the
 * Personal Care Actions, which also check the input (D-046).
 */
class RoutinePolicy
{
    public function view(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    public function update(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    public function delete(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    public function restore(User $user, Routine $routine): Response
    {
        return $this->owns($user, $routine);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Routine $routine): Response
    {
        return $user->id === $routine->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
