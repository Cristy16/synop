<?php

namespace App\Actions\Finance;

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RestoreDebtPayment
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Bring back a hidden payment and its transaction. Refused if the debt is
     * hidden, or if it would now pay more than what remains.
     * Find it with DebtPayment::onlyTrashed()->findOrFail($id).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, DebtPayment $payment): DebtPayment
    {
        Gate::forUser($user)->authorize('restore', $payment);

        return DB::transaction(function () use ($user, $payment) {
            $debt = Debt::forUser($user)->withTrashed()->lockForUpdate()->findOrFail($payment->debt_id);

            if ($debt->trashed()) {
                throw ValidationException::withMessages(['payment' => __('Restore the debt first.')]);
            }

            $remaining = $debt->remaining();

            if ($payment->amount_cents->cents() > $remaining->cents()) {
                throw ValidationException::withMessages([
                    'payment' => __('Only :remaining is left on this debt, so this payment can\'t come back.', [
                        'remaining' => $remaining->format($user->currency),
                    ]),
                ]);
            }

            $at = $payment->getRawOriginal('deleted_at');
            Transaction::forUser($user)->onlyTrashed()
                ->where('source_type', 'debt_payment')->where('source_id', $payment->id)->where('deleted_at', $at)
                ->update(['deleted_at' => null]);
            $payment->restore();

            $this->books->refresh($debt);

            return $payment;
        });
    }
}
