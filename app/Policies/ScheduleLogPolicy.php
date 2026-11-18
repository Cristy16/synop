<?php

namespace App\Policies;

use App\Models\ScheduleLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Schedule rules (no overlaps, no future logs…) live in the schedule Actions.
 */
class ScheduleLogPolicy
{
    public function view(User $user, ScheduleLog $log): Response
    {
        return $this->owns($user, $log);
    }

    public function update(User $user, ScheduleLog $log): Response
    {
        return $this->owns($user, $log);
    }

    public function delete(User $user, ScheduleLog $log): Response
    {
        return $this->owns($user, $log);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, ScheduleLog $log): Response
    {
        return $user->id === $log->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
