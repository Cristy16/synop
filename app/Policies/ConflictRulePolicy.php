<?php

namespace App\Policies;

use App\Models\ConflictRule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (no duplicates, own products only…) live in the
 * Personal Care Actions, which also check the input (D-046).
 */
class ConflictRulePolicy
{
    public function view(User $user, ConflictRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    public function update(User $user, ConflictRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    public function delete(User $user, ConflictRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    public function restore(User $user, ConflictRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, ConflictRule $rule): Response
    {
        return $user->id === $rule->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
