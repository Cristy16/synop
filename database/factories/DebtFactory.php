<?php

namespace Database\Factories;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * A bare debt row with no principal transaction, payments or task.
 * Tests of the money flow use the CreateDebt Action instead.
 *
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
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
            'contact_id' => fn (array $attributes) => Contact::factory()->state(['user_id' => $attributes['user_id']]),
            'direction' => DebtDirection::OwedToMe,
            'principal_cents' => 100000,
            'description' => null,
            'account_id' => null,
            'started_on' => now()->toDateString(),
            'due_on' => null,
            'status' => DebtStatus::Open,
        ];
    }
}
