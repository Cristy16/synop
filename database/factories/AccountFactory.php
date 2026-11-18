<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The logged-in user (BelongsToUser refuses anyone else), or a new user in a job-like context.
            'user_id' => fn () => Auth::id() ?? User::factory(),
            'name' => fake()->randomElement(['Wallet', 'GCash', 'Maya', 'BPI Savings', 'BDO']),
            'type' => AccountType::Cash,
            'opening_balance_cents' => 0,
        ];
    }

    /**
     * @param  int  $cents  opening balance in centavos
     */
    public function opening(int $cents): static
    {
        return $this->state(fn (array $attributes) => [
            'opening_balance_cents' => $cents,
        ]);
    }
}
