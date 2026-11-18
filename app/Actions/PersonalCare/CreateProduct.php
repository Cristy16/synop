<?php

namespace App\Actions\PersonalCare;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProduct
{
    public function __construct(private ProductInput $input, private ProductBookkeeping $books, private SyncIngredientTags $tags) {}

    /**
     * Add a product. Its expiry date is worked out (D-016), and with
     * "record as expense" its price is also recorded in Finance (D-045).
     *
     * @param  array<string, mixed>  $input  see ProductInput
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Product
    {
        $values = $this->input->validate($user, $input);

        return DB::transaction(function () use ($user, $values) {
            $product = new Product;
            $product->user_id = $user->id;
            ProductFields::fill($product, $values['fields']);
            $product->save();

            $this->books->syncExpense($product, $values['expense']);
            $this->tags->forProduct($product);

            return $product;
        });
    }
}
