<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VitaminDose;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (no duplicates, own products only…) live in the
 * Personal Care Actions, which also check the input (D-047).
 */
class VitaminDosePolicy
{
    public function view(User $user, VitaminDose $dose): Response
    {
        return $this->owns($user, $dose);
    }

    public function update(User $user, VitaminDose $dose): Response
    {
        return $this->owns($user, $dose);
    }

    public function delete(User $user, VitaminDose $dose): Response
    {
        return $this->owns($user, $dose);
    }

    public function restore(User $user, VitaminDose $dose): Response
    {
        return $this->owns($user, $dose);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, VitaminDose $dose): Response
    {
        return $user->id === $dose->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
