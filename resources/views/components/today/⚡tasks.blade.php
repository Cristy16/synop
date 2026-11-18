<?php

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\TaskList;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Today → Tasks: due today plus overdue. Ticked tasks stay, crossed out, until tomorrow (D-038).
 */
new class extends Component {
    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return app(TaskList::class)->dueByToday(Auth::user());
    }

    #[Computed]
    public function today(): string
    {
        return app(TaskList::class)->today(Auth::user());
    }

    public function toggle(int $id, CompleteTask $completeTask, ReopenTask $reopenTask): void
    {
        // Another user's id isn't found: 404 (rule 11).
        $task = Task::findOrFail($id);

        $task->isCompleted()
            ? $reopenTask->handle(Auth::user(), $task)
            : $completeTask->handle(Auth::user(), $task);
    }
}; ?>

@php
    $total = $this->tasks->count();
    $done = $this->tasks->filter->isCompleted()->count();
@endphp

<x-today.section
    :title="__('Tasks')"
    icon="check-circle"
    :summary="$total > 0 ? __(':done of :total done', ['done' => $done, 'total' => $total]) : null"
    :empty="__('Nothing due today.')"
    :link="route('tasks.index')"
    :link-label="__('See all tasks')"
>
    @if ($total > 0)
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->tasks as $task)
                @php $overdue = ! $task->isCompleted() && $task->due_on?->toDateString() < $this->today; @endphp
                <li wire:key="today-task-{{ $task->id }}" class="flex items-start gap-3 px-4 py-3">
                    <div class="pt-0.5">
                        <flux:checkbox
                            :checked="$task->isCompleted()"
                            wire:click="toggle({{ $task->id }})"
                            :aria-label="$task->isCompleted() ? __('Reopen :title', ['title' => $task->title]) : __('Complete :title', ['title' => $task->title])"
                        />
                    </div>

                    <div class="min-w-0 flex-1">
                        <span @class([
                            'block break-words text-sm font-medium',
                            'text-zinc-400 line-through dark:text-zinc-500' => $task->isCompleted(),
                        ])>{{ $task->title }}</span>

                        @if ($overdue || $task->due_time || $task->priority === TaskPriority::High)
                            <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($overdue)
                                    <span class="font-medium text-red-600 dark:text-red-400" data-test="overdue-label">
                                        {{ __('Overdue · :date', ['date' => $task->due_on->format('M j')]) }}
                                    </span>
                                @endif
                                @if ($task->due_time)
                                    <span>{{ \Carbon\CarbonImmutable::createFromFormat('H:i', substr($task->due_time, 0, 5))->format('g:i A') }}</span>
                                @endif
                                @if ($task->priority === TaskPriority::High && ! $task->isCompleted())
                                    <flux:badge size="sm" color="red">{{ __('High') }}</flux:badge>
                                @endif
                            </span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-today.section>
