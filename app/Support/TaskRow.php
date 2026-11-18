<?php

namespace App\Support;

use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleRule;
use App\Models\Task;

/**
 * One line in a task list: a one-off task, or one occurrence of a repeating task.
 *
 * For a repeating task, `date` is the occurrence shown (today, or the next one on
 * Upcoming; null when paused) and `status` is that day's done/skipped log.
 */
final readonly class TaskRow
{
    public function __construct(
        public Task $task,
        public ?ScheduleRule $rule = null,
        public ?string $date = null,
        public ?ScheduleLogStatus $status = null,
    ) {}

    public function isRecurring(): bool
    {
        return $this->rule !== null;
    }

    public function isDone(): bool
    {
        return $this->isRecurring() ? $this->status === ScheduleLogStatus::Done : $this->task->isCompleted();
    }

    public function isSkipped(): bool
    {
        return $this->status === ScheduleLogStatus::Skipped;
    }

    public function isPaused(): bool
    {
        return $this->rule?->isPaused() ?? false;
    }

    /**
     * A key that stays unique when a task appears once per occurrence.
     */
    public function key(): string
    {
        return $this->isRecurring() ? "rule-{$this->rule?->id}-{$this->date}" : "task-{$this->task->id}";
    }
}
