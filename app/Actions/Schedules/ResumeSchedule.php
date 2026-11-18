<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ResumeSchedule
{
    /**
     * Undo PauseSchedule on the same rule (no new version).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, ScheduleRule $rule): ScheduleRule
    {
        Gate::forUser($user)->authorize('update', $rule);

        $rule->paused_at = null;
        $rule->save();

        return $rule;
    }
}
