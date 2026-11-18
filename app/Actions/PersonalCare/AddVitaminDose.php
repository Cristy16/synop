<?php

namespace App\Actions\PersonalCare;

use App\Actions\Schedules\CreateSchedule;
use App\Models\User;
use App\Models\VitaminDose;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddVitaminDose
{
    public function __construct(private CreateSchedule $createSchedule) {}

    /**
     * Add a dose time to a vitamin, with its own schedule from today (daily
     * unless another pattern is given).
     *
     * @param  array<string, mixed>  $input  product_id, dose_time (H:i), units, meal_note,
     *                                       frequency, interval | weekdays | day_of_month
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): VitaminDose
    {
        $values = VitaminDoseInput::validate($user, $input);

        return DB::transaction(function () use ($user, $values, $input) {
            $dose = new VitaminDose;
            $dose->user_id = $user->id;
            $dose->product_id = $values['product_id'];
            $dose->dose_time = $values['dose_time'];
            $dose->units = $values['units'];
            $dose->meal_note = $values['meal_note'];
            $dose->save();

            $this->createSchedule->handle($user, $dose, [
                ...RoutineStepInput::schedule($input),
                'starts_on' => now($user->timezone)->toDateString(),
            ]);

            return $dose;
        });
    }
}
