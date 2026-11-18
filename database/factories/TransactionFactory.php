<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * Defaults to an expense. Use the states for other types.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'account_id' => fn (array $attributes) => Account::factory()->state(['user_id' => $attributes['user_id']]),
            'to_account_id' => null,
            'type' => TransactionType::Expense,
            'amount_cents' => 10000,
            'category_id' => fn (array $attributes) => TransactionCategory::factory()->state(['user_id' => $attributes['user_id']]),
            'occurred_on' => now()->toDateString(),
            'note' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TransactionType::Income,
            // A closure, so user_id is read after it has been resolved.
            'category_id' => fn (array $resolved) => TransactionCategory::factory()->income()->state(['user_id' => $resolved['user_id']]),
        ]);
    }

    public function transferTo(Account $to): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => TransactionType::Transfer,
            'to_account_id' => $to->id,
            'category_id' => null,
        ]);
    }

    public function ofType(TransactionType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'category_id' => null,
        ]);
    }
}
