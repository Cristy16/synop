<?php

namespace App\Support;

use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use Illuminate\Database\Eloquent\Model;

/**
 * One occurrence of a repeating item on a date, with what the user did about it.
 */
final readonly class ScheduledItem
{
    public function __construct(
        public ScheduleRule $rule,
        public Model $item,
        public string $date,
        public ?ScheduleLog $log,
    ) {}

    public function status(): ?ScheduleLogStatus
    {
        return $this->log?->status;
    }
}
