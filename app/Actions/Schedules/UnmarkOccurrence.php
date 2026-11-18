<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UnmarkOccurrence
{
    /**
     * Un-tick: delete the occurrence's log. Harmless if there is none.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, ScheduleRule $rule, string $date): void
    {
        Gate::forUser($user)->authorize('update', $rule);

        $date = OccurrenceDate::validate($date);

        ScheduleLog::forUser($user)
            ->where('schedule_rule_id', $rule->id)
            ->whereDate('occurs_on', $date)
            ->delete();
    }
}
