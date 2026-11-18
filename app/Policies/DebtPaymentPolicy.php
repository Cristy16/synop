<?php

namespace App\Policies;

use App\Models\DebtPayment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (open debts, overpaying…) live in the debt Actions,
 * because they need input the Policy doesn't get (D-040).
 */
class DebtPaymentPolicy
{
    public function view(User $user, DebtPayment $payment): Response
    {
        return $this->owns($user, $payment);
    }

    public function update(User $user, DebtPayment $payment): Response
    {
        return $this->owns($user, $payment);
    }

    public function delete(User $user, DebtPayment $payment): Response
    {
        return $this->owns($user, $payment);
    }

    public function restore(User $user, DebtPayment $payment): Response
    {
        return $this->owns($user, $payment);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, DebtPayment $payment): Response
    {
        return $user->id === $payment->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
