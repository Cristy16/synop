<?php

namespace App\Actions\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordTransaction
{
    use ParsesMoney;

    /**
     * Record income, an expense or a transfer typed in by the user.
     * Debt transactions are made by the debt Actions instead.
     *
     * @param  array<string, mixed>  $input  type, account_id, to_account_id (transfers), category_id
     *                                       (income/expense), amount (text, e.g. "250.75"), occurred_on (Y-m-d), note
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Transaction
    {
        $validated = Validator::make($input, TransactionInput::rules($user, $input))->validate();
        $amount = $this->parseMoney($validated['amount'], 'amount', 'positive');

        $transaction = new Transaction;
        $transaction->user_id = $user->id;
        TransactionInput::fill($transaction, $validated, $amount);
        $transaction->save();

        return $transaction;
    }
}
