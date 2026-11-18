<?php

namespace App\Services;

use App\Enums\TaskFilter;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Answers "which tasks go in this tab?" for the Tasks page. Read-only.
 */
class TaskList
{
    public const DONE_LIMIT = 100;

    /**
     * The user's tasks in one tab, in display order.
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
     * The Today page's task list: due today or earlier, and either still open
     * or completed today (they stay, crossed out, until tomorrow — D-038).
     * Debt tasks are left out: Payments Due shows those.
     * Overdue first (oldest first), then today's by time.
     *
     * @return Collection<int, Task>
     */
    public function dueByToday(User $user): Collection
    {
        $today = $this->today($user);
        // Start of the user's day, as a UTC instant to compare with completed_at (rule 9).
        $startOfToday = now($user->timezone)->startOfDay()->utc();

        return Task::forUser($user)
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
     * How many tasks each tab holds.
     *
     * @return array<string, int> keyed by TaskFilter value
     */
    public function counts(User $user): array
    {
        $counts = [];

        foreach (TaskFilter::cases() as $filter) {
            $counts[$filter->value] = $this->query($user, $filter)->count();
        }

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
     * @return Builder<Task>
     */
    private function query(User $user, TaskFilter $filter): Builder
    {
        $today = $this->today($user);
        $query = Task::forUser($user);

        return match ($filter) {
            TaskFilter::Today => $query->whereNull('completed_at')->whereDate('due_on', '=', $today),
            TaskFilter::Upcoming => $query->whereNull('completed_at')->whereDate('due_on', '>', $today),
            TaskFilter::Overdue => $query->whereNull('completed_at')->whereDate('due_on', '<', $today),
            TaskFilter::NoDate => $query->whereNull('completed_at')->whereNull('due_on'),
            TaskFilter::Done => $query->whereNotNull('completed_at'),
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
