<?php

namespace Database\Factories;

use App\Enums\CategoryKind;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<TransactionCategory>
 */
class TransactionCategoryFactory extends Factory
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
            'name' => fake()->unique()->word(),
            'kind' => CategoryKind::Expense,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => CategoryKind::Income,
        ]);
    }
}
