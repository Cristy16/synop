<?php

namespace App\Policies;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (open debts, overpaying…) live in the debt Actions,
 * because they need input the Policy doesn't get (D-040).
 */
class DebtPolicy
{
    public function view(User $user, Debt $debt): Response
    {
        return $this->owns($user, $debt);
    }

    public function update(User $user, Debt $debt): Response
    {
        return $this->owns($user, $debt);
    }

    public function delete(User $user, Debt $debt): Response
    {
        return $this->owns($user, $debt);
    }

    public function restore(User $user, Debt $debt): Response
    {
        return $this->owns($user, $debt);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Debt $debt): Response
    {
        return $user->id === $debt->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
