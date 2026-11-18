<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Services\AccountBalances;
use Illuminate\Auth\Access\Response;

class AccountPolicy
{
    public function view(User $user, Account $account): Response
    {
        return $this->owns($user, $account);
    }

    public function update(User $user, Account $account): Response
    {
        return $this->owns($user, $account);
    }

    /**
     * Only an empty account can be deleted, so the total never silently drops (D-039).
     */
    public function delete(User $user, Account $account): Response
    {
        $owns = $this->owns($user, $account);

        if ($owns->denied()) {
            return $owns;
        }

        $balance = app(AccountBalances::class)->of($account);

        if (! $balance->isZero()) {
            return Response::deny(__('Move the :amount to another account first.', [
                'amount' => $balance->format($user->currency),
            ]));
        }

        return Response::allow();
    }

    /**
     * Another user's account answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Account $account): Response
    {
        return $user->id === $account->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
