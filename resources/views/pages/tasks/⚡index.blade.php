<?php

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskFilter;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Services\TaskList;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tasks')] class extends Component {
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

    public function currentFilter(): TaskFilter
    {
        return TaskFilter::tryFrom($this->filter) ?? TaskFilter::Today;
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return app(TaskList::class)->for(Auth::user(), $this->currentFilter());
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

    /**
     * The checkbox: complete an open task, reopen a completed one.
     */
    public function toggle(int $id, CompleteTask $completeTask, ReopenTask $reopenTask): void
    {
        // Another user's id isn't found: 404 (rule 11).
        $task = Task::findOrFail($id);

        $task->isCompleted()
            ? $reopenTask->handle(Auth::user(), $task)
            : $completeTask->handle(Auth::user(), $task);
    }

    public function edit(int $id): void
    {
        $task = Task::findOrFail($id);

        $this->resetValidation();
        $this->editingId = $task->id;
        $this->title = $task->title;
        $this->notes = $task->notes ?? '';
        $this->due_on = $task->due_on?->toDateString() ?? '';
        // MySQL returns 08:00:00; the form and the Action use 08:00.
        $this->due_time = $task->due_time === null ? '' : substr($task->due_time, 0, 5);
        $this->priority = $task->priority->value;

        Flux::modal('edit-task')->show();
    }

    public function save(UpdateTask $updateTask): void
    {
        $task = Task::findOrFail($this->editingId);

        $updateTask->handle(Auth::user(), $task, [
            'title' => trim($this->title),
            'notes' => $this->notes === '' ? null : $this->notes,
            'due_on' => $this->due_on === '' ? null : $this->due_on,
            'due_time' => $this->due_time === '' ? null : $this->due_time,
            'priority' => $this->priority,
        ]);

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

    private function closeEditor(): void
    {
        Flux::modal('edit-task')->close();

        $this->reset('editingId', 'title', 'notes', 'due_on', 'due_time', 'priority');
    }
}; ?>

@php
    $tz = auth()->user()->timezone;
    $current = $this->currentFilter();
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
        @forelse ($this->tasks as $task)
            <li wire:key="task-{{ $task->id }}" class="flex items-start gap-3 px-3 py-3">
                <div class="pt-0.5">
                    <flux:checkbox
                        :checked="$task->isCompleted()"
                        wire:click="toggle({{ $task->id }})"
                        :aria-label="$task->isCompleted() ? __('Reopen :title', ['title' => $task->title]) : __('Complete :title', ['title' => $task->title])"
                    />
                </div>

                <button type="button" wire:click="edit({{ $task->id }})" class="min-w-0 flex-1 text-start">
                    <span @class([
                        'block break-words text-sm font-medium',
                        'text-zinc-400 line-through dark:text-zinc-500' => $task->isCompleted(),
                    ])>{{ $task->title }}</span>

                    <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($task->isCompleted())
                            <span>{{ __('Done :when', ['when' => $task->completed_at?->setTimezone($tz)->format('M j, g:i A')]) }}</span>
                        @elseif ($task->due_on)
                            <span @class(['text-red-600 dark:text-red-400 font-medium' => $current === TaskFilter::Overdue])>
                                {{ $current === TaskFilter::Today ? '' : $task->due_on->format('D, M j') }}
                                @if ($task->due_time)
                                    {{ \Carbon\CarbonImmutable::createFromFormat('H:i', substr($task->due_time, 0, 5))->format('g:i A') }}
                                @endif
                            </span>
                        @endif

                        @if ($task->priority !== TaskPriority::Normal)
                            <flux:badge size="sm" :color="$task->priority === TaskPriority::High ? 'red' : 'zinc'">
                                {{ __($task->priority->label()) }}
                            </flux:badge>
                        @endif

                        @if ($task->source_type)
                            <flux:badge size="sm" color="blue">{{ __('From :source', ['source' => $task->source_type]) }}</flux:badge>
                        @endif
                    </span>
                </button>
            </li>
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
                <flux:input wire:model="due_on" type="date" :label="__('Due date')" />
                <flux:input wire:model="due_time" type="time" :label="__('Time')" />
            </div>

            <flux:select wire:model="priority" :label="__('Priority')">
                @foreach (TaskPriority::cases() as $option)
                    <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

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
</section>
