<?php

namespace App\Actions\PersonalCare;

use App\Enums\TransactionType;
use App\Models\Product;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Keeps what hangs off a product in step with it: its expiry date and its
 * optional expense. Internal helper for the Personal Care Actions; callers
 * wrap it in DB::transaction() (rule 12).
 */
class ProductBookkeeping
{
    /**
     * The earlier of the printed expiry and opened + PAO, whichever exist (D-016).
     * Month ends don't overflow: opened Jan 31 + 1 month → Feb 28.
     */
    public static function expiresOn(?string $printedExpiryOn, ?string $openedOn, ?int $paoMonths): ?string
    {
        $dates = [];

        if ($printedExpiryOn !== null) {
            $dates[] = $printedExpiryOn;
        }

        if ($openedOn !== null && $paoMonths !== null) {
            $dates[] = CarbonImmutable::createFromFormat('!Y-m-d', $openedOn, 'UTC')?->addMonthsNoOverflow($paoMonths)->format('Y-m-d');
        }

        $dates = array_filter($dates);

        return $dates === [] ? null : min($dates);
    }

    /**
     * The product's expense: created, kept in step (amount, date, account, category),
     * or removed when "record as expense" is off.
     *
     * @param  array{account_id: int, category_id: int}|null  $expense
     */
    public function syncExpense(Product $product, ?array $expense): void
    {
        $transaction = $this->expenseOf($product);

        if ($expense === null || $product->price_cents === null || $product->purchased_on === null) {
            $transaction?->delete();

            return;
        }

        $transaction ??= new Transaction;
        $transaction->user_id = $product->user_id;
        $transaction->type = TransactionType::Expense;
        $transaction->account_id = $expense['account_id'];
        $transaction->to_account_id = null;
        $transaction->category_id = $expense['category_id'];
        $transaction->amount_cents = $product->price_cents;
        $transaction->setAttribute('occurred_on', $product->purchased_on->toDateString());
        $transaction->note = $product->displayName();
        $transaction->source_type = 'product';
        $transaction->source_id = $product->id;
        $transaction->save();
    }

    public function expenseOf(Product $product): ?Transaction
    {
        return Transaction::forUser($product->user_id)
            ->where('source_type', 'product')
            ->where('source_id', $product->id)
            ->first();
    }
}
