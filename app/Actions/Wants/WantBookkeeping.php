<?php

namespace App\Actions\Wants;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\Want;

/**
 * A bought want's optional expense (source = want). Internal helper for the Wants
 * Actions; callers wrap it in DB::transaction() (rule 12).
 */
class WantBookkeeping
{
    public function expenseOf(Want $want): ?Transaction
    {
        return Transaction::forUser($want->user_id)
            ->where('source_type', 'want')
            ->where('source_id', $want->id)
            ->first();
    }

    /**
     * Create or update the expense from the want's price and bought date.
     */
    public function recordExpense(Want $want, int $accountId, int $categoryId): void
    {
        $transaction = $this->expenseOf($want) ?? new Transaction;
        $transaction->user_id = $want->user_id;
        $transaction->type = TransactionType::Expense;
        $transaction->account_id = $accountId;
        $transaction->to_account_id = null;
        $transaction->category_id = $categoryId;
        $transaction->amount_cents = $want->price_cents;
        $transaction->setAttribute('occurred_on', $want->bought_on?->toDateString());
        $transaction->note = $want->title;
        $transaction->source_type = 'want';
        $transaction->source_id = $want->id;
        $transaction->save();
    }

    /**
     * After an edit: the expense's amount, date and note follow the want.
     */
    public function keepInStep(Want $want): void
    {
        $transaction = $this->expenseOf($want);

        if ($transaction === null || $want->price_cents === null || $want->bought_on === null) {
            return;
        }

        $transaction->amount_cents = $want->price_cents;
        $transaction->setAttribute('occurred_on', $want->bought_on->toDateString());
        $transaction->note = $want->title;
        $transaction->save();
    }
}
