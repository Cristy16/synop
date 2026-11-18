<?php

namespace App\Actions\Education;

use App\Models\Activity;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateActivity
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Add a quiz, exam, project or assignment, with its linked task (DATABASE §3.6).
     *
     * @param  array<string, mixed>  $input  course_id, type, title, due_on, due_time, notes
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Activity
    {
        $values = ActivityInput::validate($user, $input);
        $course = Course::forUser($user)->findOrFail($values['course_id']);

        return DB::transaction(function () use ($user, $values, $course) {
            $activity = new Activity;
            $activity->user_id = $user->id;
            ActivityFields::fill($activity, $values);
            $activity->save();

            $this->books->syncTask($activity, $course);

            return $activity;
        });
    }
}
