<?php

namespace App\Actions\PersonalCare;

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\UpdateSchedule;
use App\Models\User;
use App\Models\VitaminDose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateVitaminDose
{
    public function __construct(private UpdateSchedule $updateSchedule, private CreateSchedule $createSchedule) {}

    /**
     * Change a dose's time, tablets, meal note or schedule. The schedule changes
     * through UpdateSchedule, so its history stays (D-013, rule 15).
     *
     * @param  array<string, mixed>  $input  same fields as AddVitaminDose
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, VitaminDose $dose, array $input): VitaminDose
    {
        Gate::forUser($user)->authorize('update', $dose);

        $values = VitaminDoseInput::validate($user, $input);

        return DB::transaction(function () use ($user, $dose, $values, $input) {
            $dose->product_id = $values['product_id'];
            $dose->dose_time = $values['dose_time'];
            $dose->units = $values['units'];
            $dose->meal_note = $values['meal_note'];
            $dose->save();

            $rule = $dose->currentRule();

            $rule === null
                ? $this->createSchedule->handle($user, $dose, [...RoutineStepInput::schedule($input), 'starts_on' => now($user->timezone)->toDateString()])
                : $this->updateSchedule->handle($user, $rule, RoutineStepInput::schedule($input));

            return $dose;
        });
    }
}
