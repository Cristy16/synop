<?php

namespace App\Models;

use App\Enums\Frequency;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Which dates an item repeats on. The maths is in ScheduleEngine, not here
 * (D-015, D-042). Rules are versioned and changed only through the schedule
 * Actions (D-013), so nothing is fillable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $schedulable_type
 * @property int $schedulable_id
 * @property Frequency $frequency
 * @property int|null $interval
 * @property list<int>|null $weekdays
 * @property int|null $day_of_month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class ScheduleRule extends Model
{
    /** @use HasFactory<ScheduleRuleFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frequency' => Frequency::class,
            'interval' => 'integer',
            'weekdays' => 'array',
            'day_of_month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'paused_at' => 'datetime',
            'schedulable_id' => 'integer',
        ];
    }

    /**
     * The repeating item: a task now; routine steps, vitamin doses, classes and debts later.
     *
     * @return MorphTo<Model, $this>
     */
    public function schedulable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ScheduleLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ScheduleLog::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
