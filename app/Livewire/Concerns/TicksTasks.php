<?php

namespace App\Livewire\Concerns;

use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\UnmarkOccurrence;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;

/**
 * The checkbox and Skip button on task lists (Tasks page, Today), shared so they
 * behave the same everywhere. Only routes clicks to the right Action:
 *
 * - a debt's task opens "Record payment" (D-041);
 * - a repeating task ticks, skips or un-ticks today's occurrence (D-043);
 * - a one-off task completes or reopens.
 *
 * Another user's id isn't found: 404 (rule 11).
 */
trait TicksTasks
{
    public function toggle(int $id): void
    {
        $task = Task::findOrFail($id);
        $user = Auth::user();

        if ($task->source_type === 'debt') {
            if (! $task->isCompleted() && $task->source_id !== null) {
                $this->dispatch('record-payment', debtId: $task->source_id);
            }

            return;
        }

        $rule = $task->currentRule();

        if ($rule !== null) {
            $today = now($user->timezone)->toDateString();

            $this->todayStatus($rule, $today) === ScheduleLogStatus::Done
                ? app(UnmarkOccurrence::class)->handle($user, $rule, $today)
                : app(MarkOccurrence::class)->handle($user, $rule, $today, ScheduleLogStatus::Done);

            return;
        }

        $task->isCompleted()
            ? app(ReopenTask::class)->handle($user, $task)
            : app(CompleteTask::class)->handle($user, $task);
    }

    /**
     * Skip today's occurrence of a repeating task.
     */
    public function skip(int $id): void
    {
        $rule = Task::findOrFail($id)->currentRule();
        $user = Auth::user();

        if ($rule !== null) {
            app(MarkOccurrence::class)->handle($user, $rule, now($user->timezone)->toDateString(), ScheduleLogStatus::Skipped);
        }
    }

    /**
     * Undo a skip (or a tick): today's occurrence is open again.
     */
    public function unskip(int $id): void
    {
        $rule = Task::findOrFail($id)->currentRule();
        $user = Auth::user();

        if ($rule !== null) {
            app(UnmarkOccurrence::class)->handle($user, $rule, now($user->timezone)->toDateString());
        }
    }

    private function todayStatus(ScheduleRule $rule, string $today): ?ScheduleLogStatus
    {
        return ScheduleLog::where('schedule_rule_id', $rule->id)->whereDate('occurs_on', $today)->first()?->status;
    }
}
