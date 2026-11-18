<?php

namespace App\Services;

use App\Enums\TaskFilter;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Answers "which tasks go in this tab?" for the Tasks and Today pages. Read-only.
 *
 * One-off tasks use due_on / completed_at. Repeating tasks (D-043) appear on
 * Today on the days they're scheduled and on Upcoming with their next date;
 * never on Overdue, No date or Done (missed days don't carry over, D-042).
 */
class TaskList
{
    public const DONE_LIMIT = 100;

    public function __construct(
        private ScheduledItems $scheduledItems,
        private ScheduleEngine $engine,
    ) {}

    /**
     * The user's one-off tasks in one tab, in display order.
     *
     * @return Collection<int, Task>
     */
    public function for(User $user, TaskFilter $filter): Collection
    {
        $query = $this->query($user, $filter);

        match ($filter) {
            TaskFilter::Today => $query->orderByRaw('due_time is null')->orderBy('due_time')->tap($this->byPriority(...)),
            TaskFilter::Upcoming, TaskFilter::Overdue => $query->orderBy('due_on')->orderByRaw('due_time is null')->orderBy('due_time'),
            TaskFilter::NoDate => $query->tap($this->byPriority(...))->latest('id'),
            TaskFilter::Done => $query->latest('completed_at')->latest('id')->limit(self::DONE_LIMIT),
        };

        return $query->orderBy('id')->get();
    }

    /**
     * One tab of the Tasks page: one-off tasks plus, on Today and Upcoming, repeating ones.
     *
     * @return list<TaskRow>
     */
    public function rows(User $user, TaskFilter $filter): array
    {
        $rows = $this->for($user, $filter)->map(fn (Task $task) => new TaskRow($task, date: $task->due_on?->toDateString()))->all();

        return match ($filter) {
            TaskFilter::Today => $this->sortByTime([...$rows, ...$this->repeatingToday($user)]),
            TaskFilter::Upcoming => $this->sortByDate([...$rows, ...$this->repeatingNext($user)]),
            default => array_values($rows),
        };
    }

    /**
     * The Today page's Tasks section: one-off tasks due today or earlier that are open
     * or were completed today (they stay, crossed out, until tomorrow — D-038), then
     * today's repeating tasks. Debt tasks are left out: Payments Due shows those.
     *
     * @return list<TaskRow>
     */
    public function todayRows(User $user): array
    {
        $oneOffs = $this->dueByToday($user)->map(fn (Task $task) => new TaskRow($task, date: $task->due_on?->toDateString()))->all();

        return [...$oneOffs, ...$this->sortByTime($this->repeatingToday($user))];
    }

    /**
     * One-off tasks for the Today page (see todayRows).
     * Overdue first (oldest first), then today's by time.
     *
     * @return Collection<int, Task>
     */
    public function dueByToday(User $user): Collection
    {
        $today = $this->today($user);
        // Start of the user's day, as a UTC instant to compare with completed_at (rule 9).
        $startOfToday = now($user->timezone)->startOfDay()->utc();

        return $this->oneOff(Task::forUser($user), $user)
            // Debt tasks show in Today's "Payments Due" section instead (D-041).
            ->where(fn (Builder $q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'debt'))
            ->whereDate('due_on', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $startOfToday))
            ->orderBy('due_on')
            ->orderByRaw('due_time is null')
            ->orderBy('due_time')
            ->tap($this->byPriority(...))
            ->orderBy('id')
            ->get();
    }

    /**
     * How many open tasks each tab holds. Today counts repeating tasks not yet
     * done or skipped today; Upcoming counts every repeating task.
     *
     * @return array<string, int> keyed by TaskFilter value
     */
    public function counts(User $user): array
    {
        $counts = [];

        foreach (TaskFilter::cases() as $filter) {
            $counts[$filter->value] = $this->query($user, $filter)->count();
        }

        $counts[TaskFilter::Today->value] += count(array_filter($this->repeatingToday($user), fn (TaskRow $row) => $row->status === null));
        $counts[TaskFilter::Upcoming->value] += count($this->repeatingNext($user));

        return $counts;
    }

    /**
     * Today's date for this user, as stored in `due_on` (rule 9).
     */
    public function today(User $user): string
    {
        return now($user->timezone)->toDateString();
    }

    /**
     * @return list<TaskRow>
     */
    private function repeatingToday(User $user): array
    {
        $rows = [];

        foreach ($this->scheduledItems->on($user, $this->today($user), 'task') as $item) {
            if ($item->item instanceof Task) {
                $rows[] = new TaskRow($item->item, $item->rule, $item->date, $item->status());
            }
        }

        return $rows;
    }

    /**
     * Each repeating task with its next occurrence after today; paused ones have no date.
     *
     * @return list<TaskRow>
     */
    private function repeatingNext(User $user): array
    {
        $tomorrow = now($user->timezone)->addDay()->toDateString();

        return array_values(Task::forUser($user)
            ->whereHas('scheduleRules', fn (Builder $q) => $this->current($q, $user))
            ->with(['scheduleRules' => fn (MorphMany $rules) => $this->current($rules->getQuery(), $user)->orderBy('starts_on')])
            ->get()
            ->map(function (Task $task) use ($tomorrow) {
                /** @var ScheduleRule $rule */
                $rule = $task->scheduleRules->first();

                return new TaskRow($task, $rule, $this->engine->nextOnOrAfter($rule, $tomorrow));
            })
            // A rule that ends today has no next date; it isn't "upcoming".
            ->reject(fn (TaskRow $row) => $row->date === null && ! $row->isPaused())
            ->all());
    }

    /**
     * @return Builder<Task>
     */
    private function query(User $user, TaskFilter $filter): Builder
    {
        $today = $this->today($user);
        $query = $this->oneOff(Task::forUser($user), $user);

        return match ($filter) {
            TaskFilter::Today => $query->whereNull('completed_at')->whereDate('due_on', '=', $today),
            TaskFilter::Upcoming => $query->whereNull('completed_at')->whereDate('due_on', '>', $today),
            TaskFilter::Overdue => $query->whereNull('completed_at')->whereDate('due_on', '<', $today),
            TaskFilter::NoDate => $query->whereNull('completed_at')->whereNull('due_on'),
            TaskFilter::Done => $query->whereNotNull('completed_at'),
        };
    }

    /**
     * Leave out tasks that repeat (they have a rule that hasn't ended).
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function oneOff(Builder $query, User $user): Builder
    {
        return $query->whereDoesntHave('scheduleRules', fn (Builder $q) => $this->current($q, $user));
    }

    /**
     * Rules that haven't ended. The owner scope is dropped: a task's rules are its owner's.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function current(Builder $query, User $user): Builder
    {
        $today = $this->today($user);

        return $query->withoutGlobalScope('user')
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    /**
     * By time of day (untimed last), then high priority first.
     *
     * @param  array<int, TaskRow>  $rows
     * @return list<TaskRow>
     */
    private function sortByTime(array $rows): array
    {
        usort($rows, fn (TaskRow $a, TaskRow $b) => [$a->task->due_time === null, (string) $a->task->due_time, $this->rank($a), $a->task->id]
            <=> [$b->task->due_time === null, (string) $b->task->due_time, $this->rank($b), $b->task->id]);

        return $rows;
    }

    /**
     * By date (paused, dateless ones last), then time.
     *
     * @param  array<int, TaskRow>  $rows
     * @return list<TaskRow>
     */
    private function sortByDate(array $rows): array
    {
        usort($rows, fn (TaskRow $a, TaskRow $b) => [$a->date === null, (string) $a->date, $a->task->due_time === null, (string) $a->task->due_time, $a->task->id]
            <=> [$b->date === null, (string) $b->date, $b->task->due_time === null, (string) $b->task->due_time, $b->task->id]);

        return $rows;
    }

    private function rank(TaskRow $row): int
    {
        return match ($row->task->priority->value) {
            'high' => 0,
            'normal' => 1,
            default => 2,
        };
    }

    /**
     * High first, then normal, then low.
     *
     * @param  Builder<Task>  $query
     */
    private function byPriority(Builder $query): void
    {
        $query->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end");
    }
}
