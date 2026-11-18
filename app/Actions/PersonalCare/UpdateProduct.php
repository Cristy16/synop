<?php

namespace App\Actions\PersonalCare;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateProduct
{
    public function __construct(private ProductInput $input, private ProductBookkeeping $books, private SyncIngredientTags $tags) {}

    /**
     * Edit a product. The expiry date is worked out again and the expense
     * follows: kept in step, created, or removed when unticked.
     *
     * @param  array<string, mixed>  $input  see ProductInput
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Product $product, array $input): Product
    {
        Gate::forUser($user)->authorize('update', $product);

        $values = $this->input->validate($user, $input);

        return DB::transaction(function () use ($product, $values) {
            ProductFields::fill($product, $values['fields']);
            $product->save();

            $this->books->syncExpense($product, $values['expense']);
            $this->tags->forProduct($product);

            return $product;
        });
    }
}
