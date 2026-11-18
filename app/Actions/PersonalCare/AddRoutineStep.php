<?php

namespace App\Actions\PersonalCare;

use App\Actions\Schedules\CreateSchedule;
use App\Models\Routine;
use App\Models\RoutineStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AddRoutineStep
{
    public function __construct(private CreateSchedule $createSchedule) {}

    /**
     * Add a product at the end of the routine, with its own schedule from today
     * (daily unless another pattern is given).
     *
     * @param  array<string, mixed>  $input  product_id, note, frequency, interval | weekdays | day_of_month
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Routine $routine, array $input): RoutineStep
    {
        Gate::forUser($user)->authorize('update', $routine);

        $values = RoutineStepInput::validate($user, $input);

        return DB::transaction(function () use ($user, $routine, $values, $input) {
            $step = new RoutineStep;
            $step->user_id = $user->id;
            $step->routine_id = $routine->id;
            $step->product_id = $values['product_id'];
            $step->note = $values['note'];
            $step->position = (int) RoutineStep::forUser($user)->where('routine_id', $routine->id)->max('position') + 1;
            $step->save();

            $this->createSchedule->handle($user, $step, [
                ...RoutineStepInput::schedule($input),
                'starts_on' => now($user->timezone)->toDateString(),
            ]);

            return $step;
        });
    }
}
