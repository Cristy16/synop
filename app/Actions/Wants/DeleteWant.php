<?php

namespace App\Actions\Wants;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Want;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteWant
{
    public function __construct(private WantBookkeeping $books) {}

    /**
     * Delete a want. If it has an expense, the user is asked, as for products (D-045):
     * $putMoneyBack = true ("it was a mistake") removes the expense too; false keeps
     * it as a normal, editable expense no longer tied to the want.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Want $want, bool $putMoneyBack): void
    {
        Gate::forUser($user)->authorize('delete', $want);

        DB::transaction(function () use ($user, $want, $putMoneyBack) {
            $expense = $this->books->expenseOf($want);

            if ($expense !== null) {
                $putMoneyBack
                    ? $expense->delete()
                    : Transaction::forUser($user)->whereKey($expense->id)->update(['source_type' => null, 'source_id' => null]);
            }

            $want->delete();
        });
    }
}
