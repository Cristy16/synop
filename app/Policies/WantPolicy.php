<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Want;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (the expense, the delete question) live in the Wants Actions,
 * which also need input the Policy doesn't get (D-049).
 */
class WantPolicy
{
    public function view(User $user, Want $want): Response
    {
        return $this->owns($user, $want);
    }

    public function update(User $user, Want $want): Response
    {
        return $this->owns($user, $want);
    }

    public function delete(User $user, Want $want): Response
    {
        return $this->owns($user, $want);
    }

    public function restore(User $user, Want $want): Response
    {
        return $this->owns($user, $want);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Want $want): Response
    {
        return $user->id === $want->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
