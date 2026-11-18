<?php

namespace App\Actions\PersonalCare;

use App\Models\ConflictRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteConflictRule
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, ConflictRule $rule): void
    {
        Gate::forUser($user)->authorize('delete', $rule);

        $rule->delete();
    }
}
