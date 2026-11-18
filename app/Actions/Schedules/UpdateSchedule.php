<?php

namespace App\Actions\Schedules;

use App\Enums\Frequency;
use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateSchedule
{
    /**
     * Change an item's schedule without rewriting its history (D-013, D-042 §8).
     *
     * - Not started yet (starts today or later): edited in place.
     * - Started, and only the end date changes (to yesterday or later): edited in place.
     *   This is also what keeps an "every N days" cycle from shifting.
     * - Started, and the pattern changes: the old rule ends yesterday and a new rule
     *   starts today. Old done/skipped logs stay on the old rule.
     *
     * A started rule's start date can't move; it is ignored if given.
     *
     * @param  array<string, mixed>  $input  same fields as CreateSchedule
     * @return ScheduleRule the rule that applies from now on
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, ScheduleRule $rule, array $input): ScheduleRule
    {
        Gate::forUser($user)->authorize('update', $rule);

        $today = now($user->timezone)->toDateString();
        $yesterday = now($user->timezone)->subDay()->toDateString();
        $startsOn = $rule->starts_on->toDateString();

        if ($rule->ends_on !== null && $rule->ends_on->toDateString() < $today) {
            throw ValidationException::withMessages(['ends_on' => __('This schedule has already ended. Create a new one instead.')]);
        }

        // Not started yet: nothing in the past to protect.
        if ($startsOn >= $today) {
            $values = ScheduleInput::validate(['starts_on' => $startsOn, ...$input]);
            ScheduleInput::assertNoOverlap($user, $rule->schedulable_type, $rule->schedulable_id, $values['starts_on'], $values['ends_on'], $rule->id);
            ScheduleInput::fill($rule, $values);
            $rule->save();

            return $rule;
        }

        $values = ScheduleInput::validate([...$input, 'starts_on' => $startsOn]);

        if ($this->samePattern($rule, $values)) {
            if ($values['ends_on'] !== null && $values['ends_on'] < $yesterday) {
                throw ValidationException::withMessages([
                    'ends_on' => __('The end date can\'t be before yesterday: that would change the past.'),
                ]);
            }

            ScheduleInput::assertNoOverlap($user, $rule->schedulable_type, $rule->schedulable_id, $startsOn, $values['ends_on'], $rule->id);
            $rule->setAttribute('ends_on', $values['ends_on']);
            $rule->save();

            return $rule;
        }

        // A new version from today.
        if ($values['ends_on'] !== null && $values['ends_on'] < $today) {
            throw ValidationException::withMessages([
                'ends_on' => __('A changed schedule starts today, so it can\'t end before today.'),
            ]);
        }

        ScheduleInput::assertNoOverlap($user, $rule->schedulable_type, $rule->schedulable_id, $today, $values['ends_on'], $rule->id);

        return DB::transaction(function () use ($rule, $values, $today, $yesterday) {
            $rule->setAttribute('ends_on', $yesterday);
            $rule->save();

            $next = new ScheduleRule;
            $next->user_id = $rule->user_id;
            $next->schedulable_type = $rule->schedulable_type;
            $next->schedulable_id = $rule->schedulable_id;
            ScheduleInput::fill($next, [...$values, 'starts_on' => $today]);
            $next->paused_at = $rule->paused_at;
            $next->save();

            return $next;
        });
    }

    /**
     * Same frequency and the same interval / weekdays / day of month.
     *
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $values
     */
    private function samePattern(ScheduleRule $rule, array $values): bool
    {
        $weekdays = $rule->weekdays === null ? null : array_map('intval', $rule->weekdays);

        return $rule->frequency === $values['frequency']
            && $rule->interval === $values['interval']
            && $weekdays === $values['weekdays']
            && $rule->day_of_month === $values['day_of_month'];
    }
}
