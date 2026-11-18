<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;

/**
 * Answers "how much is in each account?" Read-only (D-031, D-039).
 *
 * Balance = opening balance
 *         + income and debt_in into the account
 *         − expense, debt_out and transfers out of it
 *         + transfers into it            (DATABASE §3.2)
 *
 * Deleted transactions don't count. Transfers cancel out in the total.
 * Sums are done in SQL, in whole centavos (rule 2).
 */
class AccountBalances
{
    /**
     * The balance of every active account, keyed by account id.
     *
     * @return array<int, Money>
     */
    public function forUser(User $user): array
    {
        $accounts = Account::forUser($user)->orderBy('id')->get();

        return $this->balancesOf($user, $accounts->pluck('opening_balance_cents', 'id')->all());
    }

    public function of(Account $account): Money
    {
        $user = $account->user;

        return $this->balancesOf($user, [$account->id => $account->opening_balance_cents])[$account->id];
    }

    /**
     * The sum over the user's active accounts.
     */
    public function total(User $user): Money
    {
        return array_reduce($this->forUser($user), fn (Money $sum, Money $balance) => $sum->add($balance), Money::zero());
    }

    /**
     * @param  array<int, Money>  $openings  opening balance keyed by account id
     * @return array<int, Money>
     */
    private function balancesOf(User $user, array $openings): array
    {
        if ($openings === []) {
            return [];
        }

        $ids = array_keys($openings);

        // Money leaving or entering through account_id: + for income/debt_in, − for everything else.
        $own = Transaction::forUser($user)
            ->whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->selectRaw(
                'account_id as id, sum(case when type in (?, ?) then amount_cents else -amount_cents end) as delta',
                [TransactionType::Income->value, TransactionType::DebtIn->value],
            )
            ->toBase()
            ->pluck('delta', 'id');

        // Transfers arriving through to_account_id.
        $transfersIn = Transaction::forUser($user)
            ->where('type', TransactionType::Transfer->value)
            ->whereIn('to_account_id', $ids)
            ->groupBy('to_account_id')
            ->selectRaw('to_account_id as id, sum(amount_cents) as delta')
            ->toBase()
            ->pluck('delta', 'id');

        $balances = [];

        foreach ($openings as $id => $opening) {
            $balances[$id] = $opening
                ->add(Money::fromCents((int) ($own[$id] ?? 0)))
                ->add(Money::fromCents((int) ($transfersIn[$id] ?? 0)));
        }

        return $balances;
    }
}
