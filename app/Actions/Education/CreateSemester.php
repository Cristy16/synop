<?php

namespace App\Actions\Education;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSemester
{
    /**
     * Add a semester. The user's first one becomes active by itself (D-048).
     *
     * @param  array<string, mixed>  $input  name, starts_on, ends_on
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Semester
    {
        $values = SemesterInput::validate($input);

        return DB::transaction(function () use ($user, $values) {
            $semester = new Semester;
            $semester->user_id = $user->id;
            $semester->name = $values['name'];
            $semester->setAttribute('starts_on', $values['starts_on']);
            $semester->setAttribute('ends_on', $values['ends_on']);
            $semester->is_active = ! Semester::forUser($user)->where('is_active', true)->exists();
            $semester->save();

            return $semester;
        });
    }
}
