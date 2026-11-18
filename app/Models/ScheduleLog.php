<?php

namespace App\Models;

use App\Enums\ScheduleLogStatus;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Done or skipped, for one occurrence of a rule. Written only by
 * MarkOccurrence / UnmarkOccurrence.
 *
 * @property int $id
 * @property int $user_id
 * @property int $schedule_rule_id
 * @property CarbonImmutable $occurs_on
 * @property ScheduleLogStatus $status
 * @property CarbonImmutable $logged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class ScheduleLog extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurs_on' => 'date',
            'status' => ScheduleLogStatus::class,
            'logged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ScheduleRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ScheduleRule::class, 'schedule_rule_id');
    }
}
