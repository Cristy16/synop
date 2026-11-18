<?php

namespace App\Actions\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTransaction
{
    use ParsesMoney;

    /**
     * Change a hand-entered transaction. Ones made by a debt or payment are refused by the Policy.
     *
     * @param  array<string, mixed>  $input  same fields as RecordTransaction
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Transaction $transaction, array $input): Transaction
    {
        Gate::forUser($user)->authorize('update', $transaction);

        $validated = Validator::make($input, TransactionInput::rules($user, $input))->validate();
        $amount = $this->parseMoney($validated['amount'], 'amount', 'positive');

        TransactionInput::fill($transaction, $validated, $amount);
        $transaction->save();

        return $transaction;
    }
}
