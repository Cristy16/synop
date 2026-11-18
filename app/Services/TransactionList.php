<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Transactions tab: one month at a time, with optional filters. Read-only.
 */
class TransactionList
{
    public const PER_PAGE = 50;

    /**
     * @param  array{month?: string|null, account_id?: int|string|null, type?: string|null, category_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Transaction>
     */
    public function for(User $user, array $filters, int $page = 1): LengthAwarePaginator
    {
        [$from, $to] = $this->monthRange($user, $filters['month'] ?? null);
        $accountId = (int) ($filters['account_id'] ?? 0);
        $type = TransactionType::tryFrom((string) ($filters['type'] ?? ''));
        $categoryId = (int) ($filters['category_id'] ?? 0);

        return Transaction::forUser($user)
            ->with(['account', 'toAccount', 'category'])
            // whereDate: works the same on MySQL DATE columns and SQLite text dates.
            ->whereDate('occurred_on', '>=', $from)
            ->whereDate('occurred_on', '<=', $to)
            // An account's transactions include transfers into it.
            ->when($accountId > 0, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('account_id', $accountId)->orWhere('to_account_id', $accountId)))
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type?->value))
            ->when($categoryId > 0, fn (Builder $q) => $q->where('category_id', $categoryId))
            ->latest('occurred_on')
            ->latest('id')
            ->paginate(self::PER_PAGE, page: $page);
    }

    /**
     * The month to show: "2026-11", or this month in the user's timezone when missing or invalid.
     */
    public function month(User $user, ?string $month): string
    {
        return $this->monthRange($user, $month)[0]->format('Y-m');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day
     */
    private function monthRange(User $user, ?string $month): array
    {
        $start = null;

        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            $start = CarbonImmutable::createFromFormat('!Y-m', $month);
        }

        $start = $start instanceof CarbonImmutable ? $start : now($user->timezone)->toImmutable()->startOfMonth();

        return [$start->startOfMonth(), $start->endOfMonth()];
    }
}
