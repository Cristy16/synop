<?php

namespace App\Actions\Finance;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteAccount
{
    /**
     * Soft-delete an account. Refused by the Policy unless its balance is zero (D-039).
     * Its past transactions stay, so it can be restored with its history.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Account $account): void
    {
        Gate::forUser($user)->authorize('delete', $account);

        $account->delete();
    }
}
