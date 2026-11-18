<?php

use App\Livewire\Concerns\TicksTasks;
use App\Services\TaskList;
use App\Support\TaskRow;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Today → Tasks: one-off tasks due today plus overdue (ticked ones stay, crossed
 * out, until tomorrow — D-038), and today's repeating tasks with Skip (D-043).
 */
new class extends Component {
    use TicksTasks;

    /**
     * @return list<TaskRow>
     */
    #[Computed]
    public function rows(): array
    {
        return app(TaskList::class)->todayRows(Auth::user());
    }
}; ?>

@php
    $tz = auth()->user()->timezone;
    $today = now($tz)->toDateString();
    // Skipped occurrences don't count either way.
    $counted = array_filter($this->rows, fn (TaskRow $row) => ! $row->isSkipped());
    $total = count($counted);
    $done = count(array_filter($counted, fn (TaskRow $row) => $row->isDone()));
@endphp

<x-today.section
    :title="__('Tasks')"
    icon="check-circle"
    :summary="$total > 0 ? __(':done of :total done', ['done' => $done, 'total' => $total]) : null"
    :empty="__('Nothing due today.')"
    :link="route('tasks.index')"
    :link-label="__('See all tasks')"
>
    @if (count($this->rows) > 0)
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->rows as $row)
                @include('partials.tasks.row', ['row' => $row, 'today' => $today, 'tz' => $tz, 'mode' => 'today'])
            @endforeach
        </ul>
    @endif
</x-today.section>
