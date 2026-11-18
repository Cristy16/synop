<?php

namespace App\Actions\Schedules;

use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Services\ScheduleEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MarkOccurrence
{
    public function __construct(private ScheduleEngine $engine) {}

    /**
     * Mark one occurrence done or skipped. Marking it again changes its status
     * (one log per rule and date). Only the user's today or earlier, and only
     * dates the rule actually falls on (D-042 §7).
     *
     * @param  string  $date  the occurrence's local date, Y-m-d
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, ScheduleRule $rule, string $date, ScheduleLogStatus $status): ScheduleLog
    {
        Gate::forUser($user)->authorize('update', $rule);

        $date = OccurrenceDate::validate($date);

        if ($date > now($user->timezone)->toDateString()) {
            throw ValidationException::withMessages(['date' => __('You can\'t mark a day that hasn\'t come yet.')]);
        }

        if (! $this->engine->occursOn($rule, $date)) {
            throw ValidationException::withMessages(['date' => __('This item isn\'t scheduled on that day.')]);
        }

        $log = ScheduleLog::forUser($user)
            ->where('schedule_rule_id', $rule->id)
            ->whereDate('occurs_on', $date)
            ->first() ?? new ScheduleLog;

        // Copied from the rule (rule 10).
        $log->user_id = $rule->user_id;
        $log->schedule_rule_id = $rule->id;
        $log->setAttribute('occurs_on', $date);
        $log->status = $status;
        $log->logged_at = now(); // a UTC instant (rule 3)
        $log->save();

        return $log;
    }
}
