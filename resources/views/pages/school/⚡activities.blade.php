<?php

use App\Actions\Education\CreateActivity;
use App\Actions\Education\DeleteActivity;
use App\Actions\Education\UpdateActivity;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Course;
use App\Models\Semester;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Activities')] class extends Component {
    /** The semester shown (kept in the address bar); empty = the active one. */
    #[Url]
    public ?int $semester = null;

    /** Opens the add window for this course (from the Courses tab). */
    #[Url(as: 'course')]
    public ?int $openFor = null;

    #[Url(as: 'done')]
    public bool $showDone = false;

    /** The activity in the edit window; null when adding. Locked against tampering. */
    #[Locked]
    public ?int $editingId = null;

    public string $course_id = '';
    public string $type = 'quiz';
    public string $title = '';
    public string $due_on = '';
    public string $due_time = '';
    public string $notes = '';

    public function mount(): void
    {
        if ($this->openFor !== null) {
            $course = Course::findOrFail($this->openFor);
            $this->openFor = null;
            $this->create();
            $this->course_id = (string) $course->id;
        }
    }

    #[Computed]
    public function current(): ?Semester
    {
        return Semester::pick($this->semester);
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::orderByRaw('archived_at is not null')->latest('starts_on')->get();
    }

    /**
     * @return Collection<int, Course>
     */
    #[Computed]
    public function courses(): Collection
    {
        return $this->current === null
            ? new Collection
            : Course::where('semester_id', $this->current->id)->orderBy('code')->orderBy('title')->get();
    }

    /**
     * The semester's activities, soonest first, each with its task's done state.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        $activities = Activity::whereIn('course_id', $this->courses->modelKeys())
            ->with('course')
            ->orderBy('due_on')
            ->orderByRaw('due_time is null')
            ->orderBy('due_time')
            ->get();

        $done = Task::where('source_type', 'activity')
            ->whereIn('source_id', $activities->modelKeys())
            ->whereNotNull('completed_at')
            ->pluck('source_id')
            ->all();

        return $activities
            ->each(fn (Activity $a) => $a->setAttribute('is_done', in_array($a->id, $done, true)))
            ->filter(fn (Activity $a) => $this->showDone || ! $a->getAttribute('is_done'))
            ->values();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->due_on = now(Auth::user()->timezone)->addWeek()->toDateString();
        $this->course_id = (string) ($this->courses->first()?->id ?? '');

        Flux::modal('activity-form')->show();
    }

    public function edit(int $id): void
    {
        $activity = Activity::findOrFail($id);

        $this->resetForm();
        $this->editingId = $activity->id;
        $this->course_id = (string) $activity->course_id;
        $this->type = $activity->type->value;
        $this->title = $activity->title;
        $this->due_on = $activity->due_on->toDateString();
        $this->due_time = $activity->due_time === null ? '' : substr($activity->due_time, 0, 5);
        $this->notes = $activity->notes ?? '';

        Flux::modal('activity-form')->show();
    }

    public function save(CreateActivity $create, UpdateActivity $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();
        $input = [
            'course_id' => $this->course_id,
            'type' => $this->type,
            'title' => $this->title,
            'due_on' => $this->due_on,
            'due_time' => $this->due_time === '' ? null : $this->due_time,
            'notes' => $this->notes,
        ];

        $this->editingId === null
            ? $create->handle(Auth::user(), $input)
            : $update->handle(Auth::user(), Activity::findOrFail($this->editingId), $input);

        Flux::modal('activity-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Activity added, with its task.') : __('Activity saved.'));
        $this->resetForm();
    }

    /**
     * The checkbox ticks the activity's task (where the done state lives).
     */
    public function toggle(int $id, CompleteTask $complete, ReopenTask $reopen): void
    {
        $task = Activity::findOrFail($id)->task();

        if ($task !== null) {
            $task->isCompleted() ? $reopen->handle(Auth::user(), $task) : $complete->handle(Auth::user(), $task);
        }
    }

    public function delete(DeleteActivity $delete): void
    {
        $delete->handle(Auth::user(), Activity::findOrFail($this->editingId));

        Flux::modal('activity-form')->close();
        Flux::toast(text: __('Activity deleted, with its task.'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'course_id', 'type', 'title', 'due_on', 'due_time', 'notes');
    }
}; ?>

@php $today = now(auth()->user()->timezone)->toDateString(); @endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-school.tabs current="school.activities" :semester="$this->current" :semesters="$this->semesters" />

    @if ($this->courses->isEmpty())
        <div class="rounded-xl border border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __('Add a course first.') }}
            <div class="mt-3"><flux:button size="sm" :href="route('school.courses', $this->current ? ['semester' => $this->current->id] : [])" wire:navigate>{{ __('Go to Courses') }}</flux:button></div>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:switch wire:model.live="showDone" :label="__('Show done')" align="left" />
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-activity-button">{{ __('Add activity') }}</flux:button>
        </div>

        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->activities as $activity)
                @php
                    $isDone = (bool) $activity->getAttribute('is_done');
                    $overdue = ! $isDone && $activity->due_on->toDateString() < $today;
                @endphp
                <li wire:key="activity-{{ $activity->id }}" class="flex items-start gap-3 px-4 py-3">
                    <div class="pt-0.5">
                        <flux:checkbox :checked="$isDone" wire:click="toggle({{ $activity->id }})" :aria-label="__('Done: :title', ['title' => $activity->title])" />
                    </div>
                    <button type="button" wire:click="edit({{ $activity->id }})" class="min-w-0 flex-1 text-start">
                        <span @class(['block break-words text-sm font-medium', 'text-zinc-400 line-through dark:text-zinc-500' => $isDone])>{{ $activity->title }}</span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <flux:badge size="sm" :color="$activity->type === ActivityType::Exam ? 'red' : 'zinc'">{{ __($activity->type->label()) }}</flux:badge>
                            <span>{{ $activity->course?->shortName() }}</span>
                            <span @class(['font-medium text-red-600 dark:text-red-400' => $overdue])>
                                {{ $overdue ? __('Overdue · was due :date', ['date' => $activity->due_on->format('M j')]) : __('Due :date', ['date' => $activity->due_on->format('D, M j')]) }}
                                @if ($activity->due_time){{ \Carbon\CarbonImmutable::createFromFormat('H:i', substr($activity->due_time, 0, 5))->format('g:i A') }}@endif
                            </span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-state">{{ __('Nothing due. Nice!') }}</li>
            @endforelse
        </ul>
    @endif

    <flux:modal name="activity-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Add activity') : __('Edit activity') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="course_id" :label="__('Course')">
                    @foreach ($this->courses as $option)
                        <flux:select.option :value="$option->id">{{ $option->shortName() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="type" :label="__('Type')">
                    @foreach (ActivityType::cases() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:input wire:model="title" :label="__('Title')" :placeholder="__('e.g. Chapter 3 quiz')" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="due_on" type="date" :label="__('Due')" required />
                <flux:input wire:model="due_time" type="time" :label="__('Time')" />
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($editingId !== null)
                    <flux:button variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this activity and its task?') }}" data-test="delete-activity-button">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-activity-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
