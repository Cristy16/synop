<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class StopSchedule
{
    /**
     * Stop an item repeating from today, keeping its history (D-043).
     *
     * - Started before today: it ends yesterday, so past done/skipped logs stay.
     * - Starts today or later: nothing to keep, so the rule is removed.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, ScheduleRule $rule): void
    {
        Gate::forUser($user)->authorize('delete', $rule);

        $today = now($user->timezone)->toDateString();

        if ($rule->starts_on->toDateString() >= $today) {
            $rule->delete(); // its logs go with it (cascading foreign key)

            return;
        }

        if ($rule->ends_on === null || $rule->ends_on->toDateString() >= $today) {
            $rule->setAttribute('ends_on', now($user->timezone)->subDay()->toDateString());
            $rule->save();
        }
    }
}
