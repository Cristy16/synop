<?php

namespace App\Actions\Education;

use App\Actions\Schedules\CreateSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AddClassSession
{
    public function __construct(private CreateSchedule $createSchedule) {}

    /**
     * Add a class time to a course. Its schedule repeats on the chosen weekdays
     * over the semester's dates (DATABASE §3.6).
     *
     * @param  array<string, mixed>  $input  start_time, end_time, weekdays, room, label
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Course $course, array $input): ClassSession
    {
        Gate::forUser($user)->authorize('update', $course);

        $values = ClassSessionInput::validate($input);
        $semester = Semester::forUser($user)->findOrFail($course->semester_id);

        return DB::transaction(function () use ($user, $course, $values, $semester) {
            $session = new ClassSession;
            $session->user_id = $user->id;
            $session->course_id = $course->id;
            $session->start_time = $values['start_time'];
            $session->end_time = $values['end_time'];
            $session->room = $values['room'];
            $session->label = $values['label'];
            $session->save();

            $this->createSchedule->handle($user, $session, [
                'frequency' => 'weekly',
                'weekdays' => $values['weekdays'],
                'starts_on' => $semester->starts_on->toDateString(),
                'ends_on' => $semester->ends_on->toDateString(),
            ]);

            return $session;
        });
    }
}
