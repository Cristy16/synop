<?php

namespace App\Actions\Finance;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteDebt
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Hide a debt with its payments and their transactions, and remove its task.
     * Balances go back to how they were before the debt. RestoreDebt undoes it.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Debt $debt): void
    {
        Gate::forUser($user)->authorize('delete', $debt);

        DB::transaction(fn () => $this->books->hide($debt, now()->startOfSecond()));
    }
}
