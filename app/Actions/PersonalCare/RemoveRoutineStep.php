<?php

namespace App\Actions\PersonalCare;

use App\Models\RoutineStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RemoveRoutineStep
{
    /**
     * Remove a step (soft delete). It stops showing up, but its schedule and its
     * done/skipped history stay for later charts (D-019).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, RoutineStep $step): void
    {
        Gate::forUser($user)->authorize('delete', $step);

        $step->delete();
    }
}
