<?php

namespace App\Actions\Education;

use App\Models\Activity;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateActivity
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Edit an activity; its task's title and due date follow (DATABASE §3.6).
     *
     * @param  array<string, mixed>  $input  course_id, type, title, due_on, due_time, notes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Activity $activity, array $input): Activity
    {
        Gate::forUser($user)->authorize('update', $activity);

        $values = ActivityInput::validate($user, $input);
        $course = Course::forUser($user)->findOrFail($values['course_id']);

        return DB::transaction(function () use ($activity, $values, $course) {
            ActivityFields::fill($activity, $values);
            $activity->save();

            $this->books->syncTask($activity, $course);

            return $activity;
        });
    }
}
