<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\WantProductType;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\Want;
use App\Support\Money;

/**
 * Builds what a verdict is based on (D-051). Every money figure is computed here,
 * in PHP (D-027), and given both as centavos and as display text, so the AI only
 * has to quote them. No names, contacts or account details (D-029): debts appear
 * only as one total. Read-only.
 */
class WantAdvisorInputs
{
    public function __construct(
        private SafeToSpend $safeToSpend,
        private IngredientCheck $ingredientCheck,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, Want $want, ?string $until = null): array
    {
        $currency = $user->currency;
        $text = fn (?Money $m) => $m?->format($currency);
        $safe = $this->safeToSpend->for($user, $until);
        $price = $want->price_cents;

        $category = null;

        if ($want->expense_category_id !== null) {
            $name = TransactionCategory::forUser($user)->whereKey($want->expense_category_id)->value('name');
            $start = now($user->timezone)->startOfMonth()->toDateString();
            $end = now($user->timezone)->endOfMonth()->toDateString();
            $spent = Money::fromCents((int) Transaction::forUser($user)
                ->where('type', TransactionType::Expense->value)
                ->where('category_id', $want->expense_category_id)
                ->whereDate('occurred_on', '>=', $start)
                ->whereDate('occurred_on', '<=', $end)
                ->sum('amount_cents'));

            $category = ['name' => $name, 'spent_this_month_cents' => $spent->cents(), 'spent_this_month_text' => $text($spent)];
        }

        $hasIngredients = in_array($want->product_type, [WantProductType::Skincare, WantProductType::Makeup], true);

        return [
            'product' => [
                'title' => $want->title,
                'type' => $want->product_type?->value,
                'shop' => $want->shop,
                'priority' => $want->priority->value,
            ],
            'price_cents' => $price?->cents(),
            'price_text' => $text($price),
            'safe_to_spend' => [
                'until' => $safe->until,
                'safe_cents' => $safe->safe->cents(),
                'safe_text' => $text($safe->safe),
                'balance_text' => $text($safe->balance),
                'owed_before_text' => $text($safe->owedTotal),
                'buffer_text' => $text($safe->buffer),
            ],
            'left_after_purchase_cents' => $price === null ? null : $safe->safe->subtract($price)->cents(),
            'left_after_purchase_text' => $price === null ? null : $text($safe->safe->subtract($price)),
            'category' => $category,
            'ingredients' => $hasIngredients ? $this->ingredientCheck->for($user, $want->ingredients_text) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public static function hash(array $inputs): string
    {
        return hash('sha256', (string) json_encode($inputs, JSON_UNESCAPED_UNICODE));
    }
}
