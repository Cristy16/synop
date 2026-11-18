<?php

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\SaveTaskRepeat;
use App\Enums\Frequency;
use App\Enums\TaskFilter;
use App\Enums\TaskPriority;
use App\Livewire\Concerns\TicksTasks;
use App\Models\Task;
use App\Services\TaskList;
use App\Support\TaskRow;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tasks')] class extends Component {
    use TicksTasks;

    /** The open tab, kept in the address bar (?filter=overdue). */
    #[Url]
    public string $filter = 'today';

    public string $quickTitle = '';

    /** The task in the edit window. Locked: the browser can't swap in another id. */
    #[Locked]
    public ?int $editingId = null;

    public string $title = '';
    public string $notes = '';
    public string $due_on = '';
    public string $due_time = '';
    public string $priority = 'normal';

    // Repeat (D-043). '' = doesn't repeat.
    public string $repeat_frequency = '';
    public string $repeat_interval = '2';
    /** @var list<string> */
    public array $repeat_weekdays = [];
    public string $repeat_day = '';
    public string $repeat_until = '';
    public bool $repeat_paused = false;

    public function currentFilter(): TaskFilter
    {
        return TaskFilter::tryFrom($this->filter) ?? TaskFilter::Today;
    }

    /**
     * @return list<TaskRow>
     */
    #[Computed]
    public function rows(): array
    {
        return app(TaskList::class)->rows(Auth::user(), $this->currentFilter());
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(TaskList::class)->counts(Auth::user());
    }

    #[Computed]
    public function editing(): ?Task
    {
        return $this->editingId === null ? null : Task::findOrFail($this->editingId);
    }

    /**
     * Quick-add: a title only. The due date follows the open tab (D-037).
     */
    public function add(CreateTask $createTask, TaskList $taskList): void
    {
        $user = Auth::user();
        $filter = $this->currentFilter();

        try {
            $createTask->handle($user, [
                'title' => trim($this->quickTitle),
                'due_on' => $filter->quickAddDueOn($taskList->today($user)),
            ]);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['quickTitle' => $e->errors()['title'] ?? []]);
        }

        $this->reset('quickTitle');

        Flux::toast(variant: 'success', text: __('Added to :tab.', ['tab' => __($filter->quickAddTarget()->label())]));
    }

    #[On('debt-paid')]
    public function refreshAfterPayment(): void
    {
        // Re-render: a paid-off debt's task is now completed.
    }

    public function edit(int $id): void
    {
        $task = Task::findOrFail($id);

        $this->resetValidation();
        $this->resetRepeat();
        $this->editingId = $task->id;
        $this->title = $task->title;
        $this->notes = $task->notes ?? '';
        $this->due_on = $task->due_on?->toDateString() ?? '';
        // MySQL returns 08:00:00; the form and the Action use 08:00.
        $this->due_time = $task->due_time === null ? '' : substr($task->due_time, 0, 5);
        $this->priority = $task->priority->value;

        if ($rule = $task->currentRule()) {
            $this->repeat_frequency = $rule->frequency->value;
            $this->repeat_interval = (string) ($rule->interval ?? 2);
            $this->repeat_weekdays = array_map('strval', $rule->weekdays ?? []);
            $this->repeat_day = (string) ($rule->day_of_month ?? '');
            $this->repeat_until = $rule->ends_on?->toDateString() ?? '';
            $this->repeat_paused = $rule->isPaused();
        }

        Flux::modal('edit-task')->show();
    }

    public function save(SaveTaskRepeat $saveTaskRepeat): void
    {
        $this->resetValidation();
        $task = Task::findOrFail($this->editingId);
        $frequency = Frequency::tryFrom($this->repeat_frequency);

        $repeat = $this->repeat_frequency === '' ? null : [
            'frequency' => $this->repeat_frequency,
            'interval' => $frequency === Frequency::EveryNDays ? $this->repeat_interval : null,
            'weekdays' => $frequency === Frequency::Weekly ? array_map('intval', $this->repeat_weekdays) : null,
            'day_of_month' => $frequency === Frequency::Monthly ? $this->repeat_day : null,
            'ends_on' => $this->repeat_until === '' ? null : $this->repeat_until,
            'paused' => $this->repeat_paused,
        ];

        try {
            $saveTaskRepeat->handle(Auth::user(), $task, [
                'title' => trim($this->title),
                'notes' => $this->notes === '' ? null : $this->notes,
                'due_on' => $this->due_on === '' ? null : $this->due_on,
                'due_time' => $this->due_time === '' ? null : $this->due_time,
                'priority' => $this->priority,
            ], $repeat);
        } catch (ValidationException $e) {
            // The schedule Actions name their fields differently from this form.
            $names = ['frequency' => 'repeat_frequency', 'interval' => 'repeat_interval', 'weekdays' => 'repeat_weekdays',
                'day_of_month' => 'repeat_day', 'ends_on' => 'repeat_until', 'starts_on' => 'repeat_frequency'];

            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                fn (array $messages, string $key) => [$names[explode('.', $key)[0]] ?? $key => $messages]
            )->all());
        }

        $this->closeEditor();

        Flux::toast(variant: 'success', text: __('Task saved.'));
    }

    public function delete(DeleteTask $deleteTask): void
    {
        $task = Task::findOrFail($this->editingId);

        $deleteTask->handle(Auth::user(), $task);

        $this->closeEditor();

        Flux::toast(text: __('Task deleted.'));
    }

    private function resetRepeat(): void
    {
        $this->reset('repeat_frequency', 'repeat_interval', 'repeat_weekdays', 'repeat_day', 'repeat_until', 'repeat_paused');
    }

    private function closeEditor(): void
    {
        Flux::modal('edit-task')->close();

        $this->reset('editingId', 'title', 'notes', 'due_on', 'due_time', 'priority');
        $this->resetRepeat();
    }
}; ?>

@php
    $tz = auth()->user()->timezone;
    $today = now($tz)->toDateString();
    $current = $this->currentFilter();
    $repeating = Frequency::tryFrom($repeat_frequency);
@endphp

<section class="mx-auto w-full max-w-2xl space-y-4">
    <flux:heading size="xl" level="1">{{ __('Tasks') }}</flux:heading>

    {{-- Tabs: scroll sideways on narrow phones --}}
    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="{{ __('Filter tasks') }}">
        @foreach (TaskFilter::cases() as $tab)
            <flux:button
                size="sm"
                :variant="$tab === $current ? 'primary' : 'ghost'"
                wire:click="$set('filter', '{{ $tab->value }}')"
                class="shrink-0"
                :aria-current="$tab === $current ? 'page' : null"
                data-test="tab-{{ $tab->value }}"
            >
                {{ __($tab->label()) }}
                @if ($this->counts[$tab->value] > 0 && $tab !== TaskFilter::Done)
                    <span @class(['ms-1 tabular-nums', 'text-red-500' => $tab === TaskFilter::Overdue && $tab !== $current])>
                        {{ $this->counts[$tab->value] }}
                    </span>
                @endif
            </flux:button>
        @endforeach
    </nav>

    {{-- Quick-add --}}
    <form wire:submit="add" class="flex gap-2">
        <div class="flex-1">
            <flux:input
                wire:model="quickTitle"
                :placeholder="$current === TaskFilter::Today ? __('Add a task for today…') : __('Add a task…')"
                :aria-label="__('New task')"
                autocomplete="off"
                data-test="quick-add-input"
            />
            <flux:error name="quickTitle" />
        </div>
        <flux:button type="submit" variant="primary" icon="plus" :aria-label="__('Add')" />
    </form>

    {{-- List --}}
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->rows as $row)
            @include('partials.tasks.row', ['row' => $row, 'today' => $today, 'tz' => $tz, 'mode' => 'page', 'current' => $current])
        @empty
            <li class="px-3 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-state">
                {{ __($current->emptyMessage()) }}
            </li>
        @endforelse
    </ul>

    {{-- Edit window --}}
    <flux:modal name="edit-task" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Edit task') }}</flux:heading>

            <flux:input wire:model="title" :label="__('Title')" required />

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />

            <div class="grid grid-cols-2 gap-3">
                @if ($repeating)
                    <div>
                        <flux:label>{{ __('Due date') }}</flux:label>
                        <flux:text class="mt-3 text-sm">{{ __('Set by the repeat') }}</flux:text>
                    </div>
                @else
                    <flux:input wire:model="due_on" type="date" :label="__('Due date')" />
                @endif
                <flux:input wire:model="due_time" type="time" :label="__('Time')" />
            </div>

            <flux:select wire:model="priority" :label="__('Priority')">
                @foreach (TaskPriority::cases() as $option)
                    <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Repeat (not for tasks made by a debt or activity) --}}
            @if ($this->editing && $this->editing->source_type === null)
                <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="repeat-section">
                    <flux:select wire:model.live="repeat_frequency" :label="__('Repeat')">
                        <flux:select.option value="">{{ __('Doesn\'t repeat') }}</flux:select.option>
                        @foreach (Frequency::cases() as $option)
                            <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    @if ($repeating === Frequency::EveryNDays)
                        <flux:input wire:model="repeat_interval" type="number" min="2" max="365" :label="__('Every how many days?')" />
                    @elseif ($repeating === Frequency::Weekly)
                        <flux:checkbox.group wire:model="repeat_weekdays" :label="__('On')" class="flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $value => $name)
                                <flux:checkbox :value="(string) $value" :label="__($name)" />
                            @endforeach
                        </flux:checkbox.group>
                    @elseif ($repeating === Frequency::Monthly)
                        <flux:input wire:model="repeat_day" type="number" min="1" max="31" :label="__('Day of the month')"
                            :description="__('29–31 fall on the last day of shorter months.')" />
                    @endif

                    @if ($repeating)
                        <flux:input wire:model="repeat_until" type="date" :label="__('Until')" :description="__('Optional')" />
                        <flux:switch wire:model="repeat_paused" :label="__('Paused')" align="left" />
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($this->editing?->isSourceProtected())
                    <flux:text class="text-sm" data-test="protected-message">
                        {{ __('To remove this task, delete the :source instead.', ['source' => $this->editing->source_type]) }}
                    </flux:text>
                @elseif ($this->editing)
                    <flux:button
                        variant="danger"
                        wire:click="delete"
                        wire:confirm="{{ __('Delete this task?') }}"
                        data-test="delete-task-button"
                    >
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <div class="ms-auto flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-task-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    <livewire:finance.record-payment />
</section>
