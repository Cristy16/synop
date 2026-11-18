<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class PauseSchedule
{
    /**
     * A paused rule has no occurrences until resumed. Done in place: past logs stay (D-042 §9).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, ScheduleRule $rule): ScheduleRule
    {
        Gate::forUser($user)->authorize('update', $rule);

        $rule->paused_at ??= now();
        $rule->save();

        return $rule;
    }
}
