<?php

namespace App\Actions\PersonalCare;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreProduct
{
    /**
     * Bring back a hidden product, and its expense if it was hidden with it.
     * Find it with Product::onlyTrashed()->findOrFail($id).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Product $product): Product
    {
        Gate::forUser($user)->authorize('restore', $product);

        DB::transaction(function () use ($user, $product) {
            Transaction::forUser($user)->onlyTrashed()
                ->where('source_type', 'product')
                ->where('source_id', $product->id)
                ->where('deleted_at', $product->getRawOriginal('deleted_at'))
                ->update(['deleted_at' => null]);

            $product->restore();
        });

        return $product;
    }
}
