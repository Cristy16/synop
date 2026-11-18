<?php

namespace App\Actions\Education;

use App\Models\Activity;
use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateCourse
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Edit a course. Its activities' task titles follow a new code or title.
     * It stays in its semester.
     *
     * @param  array<string, mixed>  $input  code, title, instructor, room
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Course $course, array $input): Course
    {
        Gate::forUser($user)->authorize('update', $course);

        $values = CourseInput::validate($user, [...$input, 'semester_id' => $course->semester_id]);

        return DB::transaction(function () use ($user, $course, $values) {
            $course->fill($values)->save();

            foreach (Activity::forUser($user)->where('course_id', $course->id)->get() as $activity) {
                $this->books->syncTask($activity, $course);
            }

            return $course;
        });
    }
}
