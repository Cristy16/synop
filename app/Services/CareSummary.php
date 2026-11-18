<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Answers questions about a user's personal-care products. Read-only.
 */
class CareSummary
{
    /** "Expiring soon" means within this many days (PRD §2.3). */
    public const EXPIRING_SOON_DAYS = 14;

    /**
     * Products still on the shelf (unopened or in use) that expire within 14 days,
     * or already have, soonest first.
     *
     * @return Collection<int, Product>
     */
    public function expiringSoon(User $user): Collection
    {
        $until = now($user->timezone)->addDays(self::EXPIRING_SOON_DAYS)->toDateString();

        return Product::forUser($user)
            ->whereIn('status', [ProductStatus::Unopened->value, ProductStatus::InUse->value])
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $until)
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * What was spent on products each month, per type, newest month first.
     * A product counts in the month it was bought, whatever its status now (D-045).
     *
     * @return list<array{month: string, types: array<string, Money>, total: Money}>
     */
    public function monthlySpending(User $user, int $months = 6): array
    {
        $now = now($user->timezone)->startOfMonth();
        $first = $now->subMonthsNoOverflow($months - 1)->toDateString();

        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $now->subMonthsNoOverflow($i)->format('Y-m');
            $rows[$month] = [
                'month' => $month,
                'types' => array_fill_keys(array_map(fn (ProductType $t) => $t->value, ProductType::cases()), Money::zero()),
                'total' => Money::zero(),
            ];
        }

        $products = Product::forUser($user)
            ->whereNotNull('price_cents')
            ->whereNotNull('purchased_on')
            ->whereDate('purchased_on', '>=', $first)
            ->get(['type', 'price_cents', 'purchased_on']);

        foreach ($products as $product) {
            $month = $product->purchased_on?->format('Y-m');

            if ($month === null || ! isset($rows[$month]) || $product->price_cents === null) {
                continue;
            }

            $type = $product->type->value;
            $rows[$month]['types'][$type] = $rows[$month]['types'][$type]->add($product->price_cents);
            $rows[$month]['total'] = $rows[$month]['total']->add($product->price_cents);
        }

        return array_values($rows);
    }
}
