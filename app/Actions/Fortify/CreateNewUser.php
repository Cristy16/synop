<?php

namespace App\Actions\Fortify;

use App\Actions\Finance\CreateDefaultCategories;
use App\Actions\PersonalCare\CreateStarterCareData;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateDefaultCategories $createDefaultCategories,
        private CreateStarterCareData $createStarterCareData,
    ) {}

    /**
     * Validate and create a newly registered user, with their starter data
     * (DATABASE §3.1), all in one database transaction (rule 12).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->createDefaultCategories->handle($user);
            $this->createStarterCareData->handle($user);

            return $user;
        });
    }
}
