<?php

namespace App\Actions\Education;

use App\Actions\Schedules\UpdateSchedule;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateClassSession
{
    public function __construct(private UpdateSchedule $updateSchedule) {}

    /**
     * Change a class time. New weekdays go through UpdateSchedule, so past weeks
     * stay as they were (D-013, rule 15).
     *
     * @param  array<string, mixed>  $input  start_time, end_time, weekdays, room, label
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, ClassSession $session, array $input): ClassSession
    {
        Gate::forUser($user)->authorize('update', $session);

        $values = ClassSessionInput::validate($input);
        $course = Course::forUser($user)->findOrFail($session->course_id);
        $semester = Semester::forUser($user)->findOrFail($course->semester_id);

        return DB::transaction(function () use ($user, $session, $values, $semester) {
            $session->start_time = $values['start_time'];
            $session->end_time = $values['end_time'];
            $session->room = $values['room'];
            $session->label = $values['label'];
            $session->save();

            $rule = $session->latestRule();
            $today = now($user->timezone)->toDateString();

            if ($rule !== null && ($rule->ends_on === null || $rule->ends_on->toDateString() >= $today)) {
                $this->updateSchedule->handle($user, $rule, [
                    'frequency' => 'weekly',
                    'weekdays' => $values['weekdays'],
                    'starts_on' => $rule->starts_on->toDateString(),
                    'ends_on' => $semester->ends_on->toDateString(),
                ]);
            } elseif ($rule !== null && array_map('intval', $rule->weekdays ?? []) !== $values['weekdays']) {
                throw ValidationException::withMessages(['weekdays' => __('This semester has ended, so its class days can\'t change.')]);
            }

            return $session;
        });
    }
}
