<?php

namespace App\Actions\Finance;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateAccount
{
    use ParsesMoney;

    /**
     * @param  array<string, mixed>  $input  name, type, opening_balance (text; blank = 0)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Account $account, array $input): Account
    {
        Gate::forUser($user)->authorize('update', $account);

        $validated = Validator::make($input, CreateAccount::rules())->validate();

        $account->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'opening_balance_cents' => $this->parseMoney($validated['opening_balance'] ?? '0', 'opening_balance', 'not_negative'),
        ])->save();

        return $account;
    }
}
