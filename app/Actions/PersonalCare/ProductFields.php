<?php

namespace App\Actions\PersonalCare;

use App\Models\Product;

/**
 * Copies validated fields onto a product and works out its expiry date.
 */
class ProductFields
{
    /**
     * @param  array<string, mixed>  $fields  from ProductInput::validate()
     */
    public static function fill(Product $product, array $fields): void
    {
        foreach ($fields as $key => $value) {
            $product->setAttribute($key, $value);
        }

        $product->setAttribute('expires_on', ProductBookkeeping::expiresOn(
            $fields['printed_expiry_on'],
            $fields['opened_on'],
            $fields['pao_months'],
        ));
    }
}
