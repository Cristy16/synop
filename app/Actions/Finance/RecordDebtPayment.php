<?php

namespace App\Actions\Finance;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordDebtPayment
{
    use ParsesMoney;

    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Pay towards a debt: saves the payment and its one transaction
     * (owed_to_me → debt_in, i_owe → debt_out). Paying the rest settles the
     * debt and completes its task. Paying more than what remains is refused.
     *
     * @param  array<string, mixed>  $input  account_id, amount (text), paid_on (Y-m-d), note
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Debt $debt, array $input): DebtPayment
    {
        Gate::forUser($user)->authorize('update', $debt);

        $validated = Validator::make($input, [
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'amount' => ['required', 'string', 'max:30'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$debt->started_on->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'paid_on.after_or_equal' => __('A payment can\'t be dated before the debt started.'),
        ])->validate();
        $amount = $this->parseMoney($validated['amount'], 'amount', 'positive');

        return DB::transaction(function () use ($user, $debt, $validated, $amount) {
            // Lock the debt so two payments at the same moment can't both pass the check below.
            $debt = Debt::forUser($user)->lockForUpdate()->findOrFail($debt->id);
            $remaining = $debt->remaining();

            if ($remaining->isZero()) {
                throw ValidationException::withMessages(['amount' => __('This debt is already settled.')]);
            }

            if ($amount->cents() > $remaining->cents()) {
                throw ValidationException::withMessages([
                    'amount' => __('Only :remaining is left to pay.', ['remaining' => $remaining->format($user->currency)]),
                ]);
            }

            $payment = new DebtPayment;
            $payment->user_id = $user->id;
            $payment->debt_id = $debt->id;
            $payment->account_id = (int) $validated['account_id'];
            $payment->amount_cents = $amount;
            $payment->setAttribute('paid_on', $validated['paid_on']);
            $payment->note = $validated['note'] ?? null;
            $payment->save();

            $this->books->recordPaymentTransaction($debt, $payment);
            $this->books->refresh($debt);

            return $payment;
        });
    }
}
