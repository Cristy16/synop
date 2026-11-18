<?php

namespace App\Actions\Education;

use App\Enums\ActivityType;
use App\Models\Activity;

class ActivityFields
{
    /**
     * @param  array{course_id: int, type: ActivityType, title: string, due_on: string, due_time: string|null, notes: string|null}  $values
     */
    public static function fill(Activity $activity, array $values): void
    {
        $activity->course_id = $values['course_id'];
        $activity->type = $values['type'];
        $activity->title = $values['title'];
        $activity->setAttribute('due_on', $values['due_on']);
        $activity->due_time = $values['due_time'];
        $activity->notes = $values['notes'];
    }
}
