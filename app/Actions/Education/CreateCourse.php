<?php

namespace App\Actions\Education;

use App\Models\Course;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CreateCourse
{
    /**
     * @param  array<string, mixed>  $input  semester_id, code, title, instructor, room
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Course
    {
        $values = CourseInput::validate($user, $input);

        $course = new Course;
        $course->user_id = $user->id;
        $course->semester_id = $values['semester_id'];
        $course->fill($values)->save();

        return $course;
    }
}
