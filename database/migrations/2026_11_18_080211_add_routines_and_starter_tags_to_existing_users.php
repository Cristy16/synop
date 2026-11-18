<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives users who registered before Phase 5's routines their AM/PM routines and the
 * starter ingredient tags (as new users get from CreateStarterCareData), and links
 * their existing products to the tags their ingredients match.
 *
 * The lists are copied here on purpose: a migration must keep doing the same thing
 * even if the app's lists change later.
 */
return new class extends Migration
{
    private const ROUTINES = ['am' => 'Morning', 'pm' => 'Night'];

    private const TAGS = [
        'AHA' => ['glycolic acid', 'lactic acid', 'mandelic acid', 'malic acid', 'tartaric acid'],
        'BHA' => ['salicylic acid', 'betaine salicylate', 'willow bark'],
        'Retinoid' => ['retinol', 'retinal', 'retinaldehyde', 'retinyl palmitate', 'tretinoin', 'adapalene', 'hydroxypinacolone retinoate'],
        'Vitamin C' => ['ascorbic acid', 'ethyl ascorbic acid', 'sodium ascorbyl phosphate', 'magnesium ascorbyl phosphate', 'ascorbyl glucoside', 'tetrahexyldecyl ascorbate'],
        'Niacinamide' => ['niacinamide'],
        'Benzoyl peroxide' => ['benzoyl peroxide'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->each(function (object $user) use ($now) {
            $routines = [];
            foreach (self::ROUTINES as $period => $name) {
                $routines[] = ['user_id' => $user->id, 'period' => $period, 'name' => $name, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('routines')->insertOrIgnore($routines);

            $tags = [];
            foreach (self::TAGS as $name => $keywords) {
                $tags[] = ['user_id' => $user->id, 'name' => $name, 'keywords' => json_encode($keywords), 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('ingredient_tags')->insertOrIgnore($tags);

            // Link existing products to the tags their ingredients match.
            $userTags = DB::table('ingredient_tags')->where('user_id', $user->id)->get(['id', 'keywords']);
            $products = DB::table('products')->where('user_id', $user->id)->whereNotNull('ingredients_text')->get(['id', 'ingredients_text']);
            $links = [];

            foreach ($products as $product) {
                $text = mb_strtolower((string) $product->ingredients_text);

                foreach ($userTags as $tag) {
                    foreach ((array) json_decode((string) $tag->keywords, true) as $keyword) {
                        if ($keyword !== '' && str_contains($text, mb_strtolower((string) $keyword))) {
                            $links[] = ['ingredient_tag_id' => $tag->id, 'product_id' => $product->id];
                            break;
                        }
                    }
                }
            }

            DB::table('ingredient_tag_product')->insertOrIgnore($links);
        });
    }

    /**
     * Nothing to undo: dropping the tables (earlier migrations) removes the rows.
     */
    public function down(): void
    {
        //
    }
};
