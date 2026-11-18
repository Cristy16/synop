<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Auth;

/**
 * A daily rule on a new task. Tests of the rules themselves use the
 * CreateSchedule Action, which validates.
 *
 * @extends Factory<ScheduleRule>
 */
class ScheduleRuleFactory extends Factory
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
            'schedulable_type' => 'task',
            'schedulable_id' => fn (array $attributes) => Task::factory()->state(['user_id' => $attributes['user_id']]),
            'frequency' => Frequency::Daily,
            'starts_on' => now()->toDateString(),
        ];
    }
}
