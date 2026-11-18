<?php

namespace App\Actions\PersonalCare;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\Task;

/**
 * The "Restock …" task for a vitamin running low (DATABASE §3.3, D-047).
 * Internal helper; callers wrap it in DB::transaction() with the stock change.
 *
 * - Stock drops to or below the "remind me at" number (from above it): the task
 *   is created, or the product's earlier one reopened (one task per product).
 * - It stays low: nothing new.
 * - Stock goes back above the number (a restock): an open reminder is ticked off.
 *
 * So a new reminder only comes after a restock above the level and a later drop.
 */
class LowStockReminder
{
    public static function isLow(?int $unitsLeft, ?int $threshold): bool
    {
        return $unitsLeft !== null && $threshold !== null && $unitsLeft <= $threshold;
    }

    public static function isProductLow(Product $product): bool
    {
        return $product->type === ProductType::Vitamin && self::isLow($product->units_left, $product->low_stock_threshold);
    }

    /**
     * Call after a product's stock or threshold changed and was saved.
     *
     * @param  bool  $wasLow  isProductLow() from before the change
     */
    public function sync(Product $product, bool $wasLow): void
    {
        $isLow = self::isProductLow($product);

        if ($wasLow === $isLow) {
            return;
        }

        $task = Task::forUser($product->user_id)
            ->where('source_type', 'product')
            ->where('source_id', $product->id)
            ->first();

        if ($isLow) {
            if ($task === null) {
                $task = new Task;
                $task->user_id = $product->user_id;
                $task->source_type = 'product';
                $task->source_id = $product->id;
            }

            $task->title = __('Restock :name', ['name' => $product->displayName()]);
            $task->setAttribute('due_on', now($product->user->timezone)->toDateString());
            $task->completed_at = null;
            $task->save();

            return;
        }

        if ($task !== null && ! $task->isCompleted()) {
            $task->completed_at = now();
            $task->save();
        }
    }
}
