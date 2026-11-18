<?php

namespace App\Policies;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Schedule rules (no overlaps, no future logs…) live in the schedule Actions.
 */
class ScheduleRulePolicy
{
    public function view(User $user, ScheduleRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    public function update(User $user, ScheduleRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    public function delete(User $user, ScheduleRule $rule): Response
    {
        return $this->owns($user, $rule);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, ScheduleRule $rule): Response
    {
        return $user->id === $rule->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
