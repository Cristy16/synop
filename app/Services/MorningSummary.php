<?php

namespace App\Services;

use App\Enums\ScheduleLogStatus;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The morning push: a short version of the Today page (PRD §2.1, D-053).
 * Counts only, no names of people or amounts. Read-only.
 */
class MorningSummary
{
    public function __construct(
        private TaskList $tasks,
        private ScheduledItems $scheduled,
        private ClassSchedule $classes,
        private DebtSummary $debts,
    ) {}

    /**
     * @return array{title: string, body: string}
     */
    public function for(User $user): array
    {
        $now = now($user->timezone)->toImmutable();
        $today = $now->toDateString();
        $open = fn ($status) => $status !== ScheduleLogStatus::Done && $status !== ScheduleLogStatus::Skipped;

        $tasks = count(array_filter($this->tasks->todayRows($user), fn ($row) => ! $row->isDone() && ! $row->isSkipped()));
        $steps = $this->scheduled->on($user, $today, 'routine_step')->filter(fn ($i) => $open($i->status()))->count();
        $vitamins = $this->scheduled->on($user, $today, 'vitamin_dose')->filter(fn ($i) => $open($i->status()))->count();
        $classes = $this->classes->on($user, $today);
        $payments = count($this->debts->dueSoon($user));

        $parts = [];

        if ($tasks > 0) {
            $parts[] = trans_choice(':count task|:count tasks', $tasks);
        }

        if ($classes !== []) {
            $parts[] = trans_choice(':count class, first at :time|:count classes, first at :time', count($classes), [
                'time' => CarbonImmutable::parse($classes[0]->start_time)->format('g:i A'),
            ]);
        }

        if ($steps > 0) {
            $parts[] = trans_choice(':count routine step|:count routine steps', $steps);
        }

        if ($vitamins > 0) {
            $parts[] = trans_choice(':count vitamin|:count vitamins', $vitamins);
        }

        if ($payments > 0) {
            $parts[] = trans_choice(':count payment due soon|:count payments due soon', $payments);
        }

        return [
            'title' => __('Good morning! Your :day', ['day' => $now->format('l')]),
            'body' => $parts === [] ? __('Nothing scheduled today.') : implode(' · ', $parts),
        ];
    }
}
