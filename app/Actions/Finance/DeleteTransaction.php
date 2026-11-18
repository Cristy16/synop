<?php

namespace App\Actions\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteTransaction
{
    /**
     * Soft-delete: the row is hidden and stops counting in balances, and can be restored.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Transaction $transaction): void
    {
        Gate::forUser($user)->authorize('delete', $transaction);

        $transaction->delete();
    }
}
