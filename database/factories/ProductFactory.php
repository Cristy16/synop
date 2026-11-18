<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * A bare product row. Tests of expiry and expenses use the CreateProduct Action.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn () => Auth::id() ?? User::factory(),
            'type' => ProductType::Skincare,
            'brand' => fake()->randomElement(['COSRX', 'Celeteque', 'Belo', 'The Ordinary']),
            'name' => fake()->randomElement(['Toner', 'Serum', 'Sunscreen', 'Moisturizer']),
            'quantity' => 1,
            'status' => ProductStatus::InUse,
        ];
    }
}
