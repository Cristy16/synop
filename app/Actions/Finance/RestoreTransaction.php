<?php

namespace App\Actions\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RestoreTransaction
{
    /**
     * Bring back a deleted transaction, so it counts in balances again.
     * Find it with Transaction::onlyTrashed()->findOrFail($id).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Transaction $transaction): Transaction
    {
        Gate::forUser($user)->authorize('restore', $transaction);

        $transaction->restore();

        return $transaction;
    }
}
