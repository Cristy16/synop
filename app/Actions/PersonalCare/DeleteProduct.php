<?php

namespace App\Actions\PersonalCare;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteProduct
{
    public function __construct(private ProductBookkeeping $books) {}

    /**
     * Hide a product (soft delete). If it has an expense, the user is asked (D-045):
     *
     * - $putMoneyBack = true ("it was a mistake"): the expense is hidden with it, so
     *   the money is back in the account. RestoreProduct brings both back.
     * - false: the expense stays as a normal, editable expense, no longer tied to the product.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Product $product, bool $putMoneyBack): void
    {
        Gate::forUser($user)->authorize('delete', $product);

        DB::transaction(function () use ($user, $product, $putMoneyBack) {
            // One moment for both, so a restore brings back exactly this pair.
            $at = now()->startOfSecond();
            $expense = $this->books->expenseOf($product);

            if ($expense !== null) {
                Transaction::forUser($user)->whereKey($expense->id)->update($putMoneyBack
                    ? ['deleted_at' => $at]
                    : ['source_type' => null, 'source_id' => null]);
            }

            Product::forUser($user)->whereKey($product->id)->update(['deleted_at' => $at]);
            $product->deleted_at = $at;
        });
    }
}
