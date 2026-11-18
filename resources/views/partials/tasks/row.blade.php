{{--
    One task line, shared by the Tasks page and Today → Tasks.
    Expects: $row (App\Support\TaskRow), $today (Y-m-d), $tz,
             $mode ('page' or 'today'), and on the page $current (TaskFilter).
--}}
@php
    $task = $row->task;
    $done = $row->isDone();
    $skipped = $row->isSkipped();
    $future = $row->isRecurring() && ($row->date === null || $row->date > $today);
    $overdue = ! $row->isRecurring() && ! $done && $task->due_on !== null && $task->due_on->toDateString() < $today;
    $time = $task->due_time ? \Carbon\CarbonImmutable::createFromFormat('H:i', substr($task->due_time, 0, 5))->format('g:i A') : null;
    $current ??= null;
@endphp

<li wire:key="{{ $mode }}-{{ $row->key() }}" @class(['flex items-start gap-3 py-3', 'px-3' => $mode === 'page', 'px-4' => $mode === 'today'])>
    <div class="pt-0.5">
        @if ($future)
            {{-- A later occurrence can't be ticked yet. --}}
            <flux:icon name="arrow-path" variant="mini" class="text-zinc-400" :aria-label="__('Repeats')" />
        @else
            <flux:checkbox
                :checked="$done"
                wire:click="toggle({{ $task->id }})"
                :aria-label="$done ? __('Reopen :title', ['title' => $task->title]) : __('Complete :title', ['title' => $task->title])"
            />
        @endif
    </div>

    <div class="min-w-0 flex-1">
        @if ($mode === 'page')
            <button type="button" wire:click="edit({{ $task->id }})" class="block w-full text-start">
        @endif
        <span @class([
            'block break-words text-sm font-medium',
            'text-zinc-400 line-through dark:text-zinc-500' => $done,
            'text-zinc-400 dark:text-zinc-500' => $skipped,
        ])>{{ $task->title }}</span>
        @if ($mode === 'page')
            </button>
        @endif

        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
            @if ($row->isRecurring())
                <span data-test="repeat-label">↻ {{ $row->rule?->summary() }}</span>
                @if ($row->isPaused())
                    <flux:badge size="sm" color="zinc">{{ __('Paused') }}</flux:badge>
                @elseif ($future)
                    <span>{{ \Carbon\CarbonImmutable::parse($row->date)->format('D, M j') }}</span>
                @endif
                @if ($time)<span>{{ $time }}</span>@endif

                @if ($skipped)
                    <span>{{ __('Skipped') }}</span>
                    <button type="button" wire:click="unskip({{ $task->id }})" class="underline" data-test="undo-skip">{{ __('Undo') }}</button>
                @elseif (! $done && ! $future)
                    <button type="button" wire:click="skip({{ $task->id }})" class="underline" data-test="skip">{{ __('Skip') }}</button>
                @endif
            @elseif ($mode === 'today')
                @if ($overdue)
                    <span class="font-medium text-red-600 dark:text-red-400" data-test="overdue-label">
                        {{ __('Overdue · :date', ['date' => $task->due_on->format('M j')]) }}
                    </span>
                @endif
                @if ($time)<span>{{ $time }}</span>@endif
            @elseif ($task->isCompleted())
                <span>{{ __('Done :when', ['when' => $task->completed_at?->setTimezone($tz)->format('M j, g:i A')]) }}</span>
            @elseif ($task->due_on)
                <span @class(['text-red-600 dark:text-red-400 font-medium' => $current === \App\Enums\TaskFilter::Overdue])>
                    {{ $current === \App\Enums\TaskFilter::Today ? '' : $task->due_on->format('D, M j') }}
                    {{ $time }}
                </span>
            @endif

            @if ($task->priority === \App\Enums\TaskPriority::High && ! $done)
                <flux:badge size="sm" color="red">{{ __('High') }}</flux:badge>
            @elseif ($task->priority === \App\Enums\TaskPriority::Low && $mode === 'page' && ! $done)
                <flux:badge size="sm" color="zinc">{{ __('Low') }}</flux:badge>
            @endif

            @if ($task->source_type && $mode === 'page')
                <flux:badge size="sm" color="blue">{{ __('From :source', ['source' => $task->source_type]) }}</flux:badge>
            @endif
        </span>
    </div>
</li>
