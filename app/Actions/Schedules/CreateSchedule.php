<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateSchedule
{
    /**
     * Make an item repeat. The item (a task now; routine steps, vitamins, classes,
     * debts later) is passed by the module's own Action, never chosen by the user.
     *
     * @param  array<string, mixed>  $input  frequency, interval | weekdays | day_of_month, starts_on, ends_on (Y-m-d)
     *
     * @throws ValidationException
     * @throws LogicException when the item belongs to another user
     */
    public function handle(User $user, Model $schedulable, array $input): ScheduleRule
    {
        // A record must never link to another user's record (rule 10).
        if ((int) $schedulable->getAttribute('user_id') !== $user->id) {
            throw new LogicException('A schedule can only be attached to an item owned by the same user.');
        }

        // Throws if the item's model is missing from the morph map (rule 14).
        $type = $schedulable->getMorphClass();
        $id = (int) $schedulable->getKey();

        $values = ScheduleInput::validate($input);
        ScheduleInput::assertNoOverlap($user, $type, $id, $values['starts_on'], $values['ends_on']);

        $rule = new ScheduleRule;
        $rule->user_id = $user->id;
        $rule->schedulable_type = $type;
        $rule->schedulable_id = $id;
        ScheduleInput::fill($rule, $values);
        $rule->save();

        return $rule;
    }
}
