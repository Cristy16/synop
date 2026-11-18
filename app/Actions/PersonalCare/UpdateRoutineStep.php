<?php

namespace App\Actions\PersonalCare;

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\UpdateSchedule;
use App\Models\RoutineStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateRoutineStep
{
    public function __construct(private UpdateSchedule $updateSchedule, private CreateSchedule $createSchedule) {}

    /**
     * Change a step's product, note or schedule. The schedule changes through
     * UpdateSchedule, so its done/skipped history stays (D-013, rule 15).
     *
     * @param  array<string, mixed>  $input  product_id, note, frequency, interval | weekdays | day_of_month
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, RoutineStep $step, array $input): RoutineStep
    {
        Gate::forUser($user)->authorize('update', $step);

        $values = RoutineStepInput::validate($user, $input);

        return DB::transaction(function () use ($user, $step, $values, $input) {
            $step->product_id = $values['product_id'];
            $step->note = $values['note'];
            $step->save();

            $rule = $step->currentRule();

            $rule === null
                ? $this->createSchedule->handle($user, $step, [...RoutineStepInput::schedule($input), 'starts_on' => now($user->timezone)->toDateString()])
                : $this->updateSchedule->handle($user, $rule, RoutineStepInput::schedule($input));

            return $step;
        });
    }
}
