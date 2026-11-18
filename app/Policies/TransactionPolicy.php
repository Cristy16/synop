<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): Response
    {
        return $this->owns($user, $transaction);
    }

    public function update(User $user, Transaction $transaction): Response
    {
        return $this->ownsAndManual($user, $transaction);
    }

    public function delete(User $user, Transaction $transaction): Response
    {
        return $this->ownsAndManual($user, $transaction);
    }

    public function restore(User $user, Transaction $transaction): Response
    {
        return $this->ownsAndManual($user, $transaction);
    }

    /**
     * A transaction made by a debt or payment changes only through that record,
     * so the two can't drift apart (DATABASE §3.2).
     */
    private function ownsAndManual(User $user, Transaction $transaction): Response
    {
        $owns = $this->owns($user, $transaction);

        if ($owns->denied()) {
            return $owns;
        }

        return $transaction->hasSource()
            ? Response::deny(__('This transaction was made by a :source. Change the :source instead.', [
                'source' => str_replace('_', ' ', (string) $transaction->source_type),
            ]))
            : Response::allow();
    }

    /**
     * Another user's transaction answers 404 (CLAUDE.md rule 11).
     */
    private function owns(User $user, Transaction $transaction): Response
    {
        return $user->id === $transaction->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
