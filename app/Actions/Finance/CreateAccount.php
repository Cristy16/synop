<?php

namespace App\Actions\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateAccount
{
    use ParsesMoney;

    /**
     * @param  array<string, mixed>  $input  name, type, opening_balance (text, e.g. "1,500.50"; blank = 0)
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Account
    {
        $validated = Validator::make($input, self::rules())->validate();

        $account = new Account([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'opening_balance_cents' => $this->parseMoney($validated['opening_balance'] ?? '0', 'opening_balance', 'not_negative'),
        ]);
        $account->user_id = $user->id;
        $account->save();

        return $account;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'opening_balance' => ['nullable', 'string', 'max:30'],
        ];
    }
}
