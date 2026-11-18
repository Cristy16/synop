<?php

namespace App\Actions\PersonalCare;

use App\Models\User;
use App\Models\VitaminDose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RemoveVitaminDose
{
    /**
     * Remove a dose (soft delete). It stops showing up; its schedule and history stay (D-019).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, VitaminDose $dose): void
    {
        Gate::forUser($user)->authorize('delete', $dose);

        $dose->delete();
    }
}
