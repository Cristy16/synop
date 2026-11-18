<?php

namespace App\Actions\Schedules;

use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class RemoveSchedules
{
    /**
     * Remove every version of an item's schedule, for items whose history lives
     * elsewhere: a debt switching back to one payment keeps its history in its
     * payments, not in schedule logs (D-044). Repeating tasks use StopSchedule instead.
     *
     * @throws LogicException when the item belongs to another user
     */
    public function handle(User $user, Model $schedulable): void
    {
        if ((int) $schedulable->getAttribute('user_id') !== $user->id) {
            throw new LogicException('Only the owner can remove an item\'s schedules.');
        }

        ScheduleRule::forUser($user)
            ->where('schedulable_type', $schedulable->getMorphClass())
            ->where('schedulable_id', $schedulable->getKey())
            ->delete();
    }
}
