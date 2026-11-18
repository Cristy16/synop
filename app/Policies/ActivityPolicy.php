<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (one active semester, the linked task…) live in
 * the Education Actions, which also check the input (D-048).
 */
class ActivityPolicy
{
    public function view(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    public function update(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    public function delete(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    public function restore(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Activity $activity): Response
    {
        return $user->id === $activity->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
