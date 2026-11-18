<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (one active semester, the linked task…) live in
 * the Education Actions, which also check the input (D-048).
 */
class ClassSessionPolicy
{
    public function view(User $user, ClassSession $session): Response
    {
        return $this->owns($user, $session);
    }

    public function update(User $user, ClassSession $session): Response
    {
        return $this->owns($user, $session);
    }

    public function delete(User $user, ClassSession $session): Response
    {
        return $this->owns($user, $session);
    }

    public function restore(User $user, ClassSession $session): Response
    {
        return $this->owns($user, $session);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, ClassSession $session): Response
    {
        return $user->id === $session->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
