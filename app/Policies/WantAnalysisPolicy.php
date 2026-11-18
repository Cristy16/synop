<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WantAnalysis;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (the expense, the delete question) live in the WantAnalysiss Actions,
 * which also need input the Policy doesn't get (D-051).
 */
class WantAnalysisPolicy
{
    public function view(User $user, WantAnalysis $analysis): Response
    {
        return $this->owns($user, $analysis);
    }

    public function update(User $user, WantAnalysis $analysis): Response
    {
        return $this->owns($user, $analysis);
    }

    public function delete(User $user, WantAnalysis $analysis): Response
    {
        return $this->owns($user, $analysis);
    }

    public function restore(User $user, WantAnalysis $analysis): Response
    {
        return $this->owns($user, $analysis);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, WantAnalysis $analysis): Response
    {
        return $user->id === $analysis->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
