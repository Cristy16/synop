<?php

namespace App\Actions\PersonalCare;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RestockVitamin
{
    public function __construct(private LowStockReminder $reminder) {}

    /**
     * Add tablets to a vitamin (e.g. a new bottle of 60). Going back above the
     * "remind me at" number ticks off an open "Restock" task (D-047).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Product $product, mixed $units): Product
    {
        Gate::forUser($user)->authorize('update', $product);

        $validated = Validator::make(['units' => $units], ['units' => ['required', 'integer', 'between:1,10000']])->validate();

        if ($product->type !== ProductType::Vitamin) {
            throw ValidationException::withMessages(['units' => __('Only vitamins have tablets to restock.')]);
        }

        return DB::transaction(function () use ($user, $product, $validated) {
            $product = Product::forUser($user)->lockForUpdate()->findOrFail($product->id);
            $wasLow = LowStockReminder::isProductLow($product);

            $product->units_left = ($product->units_left ?? 0) + (int) $validated['units'];
            $product->save();

            $this->reminder->sync($product, $wasLow);

            return $product;
        });
    }
}
