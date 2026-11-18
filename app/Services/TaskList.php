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
