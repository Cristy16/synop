<?php

use App\Actions\Education\ArchiveSemester;
use App\Actions\Education\CreateSemester;
use App\Actions\Education\DeleteSemester;
use App\Actions\Education\SetActiveSemester;
use App\Actions\Education\UpdateSemester;
use App\Models\Semester;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Semesters')] class extends Component {
    /** Which semester the other tabs open on (kept in the address bar). */
    #[Url]
    public ?int $semester = null;

    /** The semester in the edit window; null when adding. Locked against tampering. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $starts_on = '';
    public string $ends_on = '';

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::withCount('courses')->orderByRaw('archived_at is not null')->latest('starts_on')->get();
    }

    #[Computed]
    public function current(): ?Semester
    {
        return Semester::pick($this->semester);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('semester-form')->show();
    }

    public function edit(int $id): void
    {
        $semester = Semester::findOrFail($id);

        $this->resetForm();
        $this->editingId = $semester->id;
        $this->name = $semester->name;
        $this->starts_on = $semester->starts_on->toDateString();
        $this->ends_on = $semester->ends_on->toDateString();

        Flux::modal('semester-form')->show();
    }

    public function save(CreateSemester $create, UpdateSemester $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();
        $input = ['name' => $this->name, 'starts_on' => $this->starts_on, 'ends_on' => $this->ends_on];

        $this->editingId === null
            ? $create->handle(Auth::user(), $input)
            : $update->handle(Auth::user(), Semester::findOrFail($this->editingId), $input);

        Flux::modal('semester-form')->close();
        Flux::toast(variant: 'success', text: __('Semester saved.'));
        $this->resetForm();
    }

    public function setActive(int $id, SetActiveSemester $setActive): void
    {
        $setActive->handle(Auth::user(), Semester::findOrFail($id));
        $this->semester = $id;

        Flux::toast(variant: 'success', text: __('School now opens on this semester.'));
    }

    public function archive(int $id, ArchiveSemester $archive): void
    {
        $archive->handle(Auth::user(), Semester::findOrFail($id));

        Flux::toast(text: __('Archived. Its classes are hidden from Today.'));
    }

    public function unarchive(int $id, ArchiveSemester $archive): void
    {
        $archive->handle(Auth::user(), Semester::findOrFail($id), archived: false);

        Flux::toast(text: __('Unarchived.'));
    }

    public function delete(int $id, DeleteSemester $delete): void
    {
        $delete->handle(Auth::user(), Semester::findOrFail($id));

        if ($this->semester === $id) {
            $this->semester = null;
        }

        Flux::modal('semester-form')->close();
        Flux::toast(text: __('Semester deleted.'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'starts_on', 'ends_on');
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-school.tabs current="school.semesters" :semester="$this->current" :picker="false" />

    <div class="flex justify-end">
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-semester-button">{{ __('Add semester') }}</flux:button>
    </div>

    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->semesters as $item)
            <li wire:key="semester-{{ $item->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <button type="button" wire:click="edit({{ $item->id }})" class="min-w-0 flex-1 text-start">
                    <span @class(['block text-sm font-medium', 'text-zinc-400 dark:text-zinc-500' => $item->isArchived()])>{{ $item->name }}</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $item->starts_on->format('M j, Y') }} – {{ $item->ends_on->format('M j, Y') }}
                        · {{ trans_choice(':count course|:count courses', $item->courses_count) }}
                    </span>
                </button>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($item->is_active)
                        <flux:badge size="sm" color="green" data-test="active-badge">{{ __('Active') }}</flux:badge>
                    @elseif ($item->isArchived())
                        <flux:badge size="sm" color="zinc">{{ __('Archived') }}</flux:badge>
                        <flux:button size="xs" variant="ghost" wire:click="unarchive({{ $item->id }})">{{ __('Unarchive') }}</flux:button>
                    @else
                        <flux:button size="xs" variant="ghost" wire:click="setActive({{ $item->id }})" data-test="set-active-{{ $item->id }}">{{ __('Set active') }}</flux:button>
                    @endif
                    @unless ($item->isArchived())
                        <flux:button size="xs" variant="ghost" wire:click="archive({{ $item->id }})" data-test="archive-{{ $item->id }}">{{ __('Archive') }}</flux:button>
                    @endunless
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No semesters yet. Add your current one to start.') }}</li>
        @endforelse
    </ul>

    <flux:modal name="semester-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Add semester') : __('Edit semester') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. 1st Sem AY 2026–2027')" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="starts_on" type="date" :label="__('Starts')" required />
                <flux:input wire:model="ends_on" type="date" :label="__('Ends')" required />
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($editingId !== null)
                    <flux:button variant="danger" wire:click="delete({{ $editingId }})"
                        wire:confirm="{{ __('Delete this semester with all its courses, class times and activities (and their tasks)? To keep them, archive it instead.') }}"
                        data-test="delete-semester-button">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-semester-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
