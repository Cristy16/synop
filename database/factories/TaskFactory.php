<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'title' => fake()->sentence(4),
            'notes' => null,
            'due_on' => null,
            'due_time' => null,
            'priority' => TaskPriority::Normal,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }

    public function dueOn(string $date, ?string $time = null): static
    {
        return $this->state(fn (array $attributes) => [
            'due_on' => $date,
            'due_time' => $time,
        ]);
    }
}
