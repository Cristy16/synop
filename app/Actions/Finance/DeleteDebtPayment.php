<?php

namespace App\Actions\Finance;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteDebtPayment
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Hide a payment and its transaction. A settled debt opens again and its task is unticked.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, DebtPayment $payment): void
    {
        Gate::forUser($user)->authorize('delete', $payment);

        DB::transaction(function () use ($user, $payment) {
            $at = now()->startOfSecond();

            Transaction::forUser($user)->where('source_type', 'debt_payment')->where('source_id', $payment->id)
                ->update(['deleted_at' => $at]);
            DebtPayment::forUser($user)->whereKey($payment->id)->update(['deleted_at' => $at]);
            $payment->deleted_at = $at;

            $this->books->refresh(Debt::forUser($user)->findOrFail($payment->debt_id));
        });
    }
}
