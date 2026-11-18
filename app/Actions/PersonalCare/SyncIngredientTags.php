<?php

namespace App\Actions\PersonalCare;

use App\Models\IngredientTag;
use App\Models\Product;

/**
 * Rebuilds which products have which ingredient tags (the ingredient_tag_product
 * pivot). Run whenever a product's ingredients or a tag's keywords change
 * (DATABASE §3.5). A product has a tag when any of the tag's keywords appears in
 * its ingredient list, ignoring case.
 */
class SyncIngredientTags
{
    /**
     * @param  list<string>  $keywords
     */
    public static function matches(?string $ingredients, array $keywords): bool
    {
        if ($ingredients === null || trim($ingredients) === '') {
            return false;
        }

        $text = mb_strtolower($ingredients);

        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));

            if ($keyword !== '' && str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function forProduct(Product $product): void
    {
        $tagIds = IngredientTag::forUser($product->user_id)->get()
            ->filter(fn (IngredientTag $tag) => self::matches($product->ingredients_text, $tag->keywords))
            ->modelKeys();

        $product->tags()->sync($tagIds);
    }

    public function forTag(IngredientTag $tag): void
    {
        $productIds = Product::forUser($tag->user_id)->withTrashed()->whereNotNull('ingredients_text')->get(['id', 'ingredients_text'])
            ->filter(fn (Product $product) => self::matches($product->ingredients_text, $tag->keywords))
            ->modelKeys();

        $tag->products()->sync($productIds);
    }
}
