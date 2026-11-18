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

class UpdateSemester
{
    public function __construct(private UpdateSchedule $updateSchedule) {}

    /**
     * Rename a semester or change its dates. Its class schedules follow the new
     * dates as far as the past allows (D-048): a schedule that hasn't started
     * moves completely; a running one only gets its end date (if not before
     * yesterday). Changes go through UpdateSchedule (rule 15).
     *
     * @param  array<string, mixed>  $input  name, starts_on, ends_on
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Semester $semester, array $input): Semester
    {
        Gate::forUser($user)->authorize('update', $semester);

        $values = SemesterInput::validate($input);
        $datesChanged = $values['starts_on'] !== $semester->starts_on->toDateString() || $values['ends_on'] !== $semester->ends_on->toDateString();

        return DB::transaction(function () use ($user, $semester, $values, $datesChanged) {
            $semester->name = $values['name'];
            $semester->setAttribute('starts_on', $values['starts_on']);
            $semester->setAttribute('ends_on', $values['ends_on']);
            $semester->save();

            if ($datesChanged) {
                $this->moveSchedules($user, $semester, $values['starts_on'], $values['ends_on']);
            }

            return $semester;
        });
    }

    private function moveSchedules(User $user, Semester $semester, string $startsOn, string $endsOn): void
    {
        $today = now($user->timezone)->toDateString();
        $yesterday = now($user->timezone)->subDay()->toDateString();
        $courseIds = Course::forUser($user)->where('semester_id', $semester->id)->pluck('id');

        foreach (ClassSession::forUser($user)->whereIn('course_id', $courseIds)->get() as $session) {
            $rule = $session->latestRule();

            if ($rule === null || ($rule->ends_on !== null && $rule->ends_on->toDateString() < $today)) {
                continue;
            }

            $pattern = ['frequency' => $rule->frequency->value, 'weekdays' => $rule->weekdays];

            if ($rule->starts_on->toDateString() >= $today) {
                $this->updateSchedule->handle($user, $rule, [...$pattern, 'starts_on' => max($startsOn, $today), 'ends_on' => $endsOn]);
            } elseif ($endsOn >= $yesterday) {
                $this->updateSchedule->handle($user, $rule, [...$pattern, 'ends_on' => $endsOn]);
            }
        }
    }
}
