<?php

use App\Actions\PersonalCare\CreateConflictRule;
use App\Actions\PersonalCare\CreateIngredientTag;
use App\Actions\PersonalCare\DeleteConflictRule;
use App\Actions\PersonalCare\DeleteIngredientTag;
use App\Actions\PersonalCare\UpdateIngredientTag;
use App\Enums\ConflictScope;
use App\Models\ConflictRule;
use App\Models\IngredientTag;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Conflict rules')] class extends Component {
    // Ingredient group window. The id is locked against tampering.
    #[Locked]
    public ?int $editingTagId = null;
    public string $name = '';
    public string $keywords = '';

    // Rule window.
    public string $tag_a_id = '';
    public string $tag_b_id = '';
    public string $scope = 'same_routine';
    public string $note = '';

    /**
     * @return Collection<int, IngredientTag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return IngredientTag::withCount('products')->orderBy('name')->get();
    }

    /**
     * @return Collection<int, ConflictRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return ConflictRule::with('tagA', 'tagB')->orderBy('id')->get();
    }

    public function createTag(): void
    {
        $this->resetTagForm();
        Flux::modal('tag-form')->show();
    }

    public function editTag(int $id): void
    {
        $tag = IngredientTag::findOrFail($id);

        $this->resetTagForm();
        $this->editingTagId = $tag->id;
        $this->name = $tag->name;
        $this->keywords = implode("\n", $tag->keywords);

        Flux::modal('tag-form')->show();
    }

    public function saveTag(CreateIngredientTag $create, UpdateIngredientTag $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();
        $input = ['name' => $this->name, 'keywords' => $this->keywords];

        $this->editingTagId === null
            ? $create->handle(Auth::user(), $input)
            : $update->handle(Auth::user(), IngredientTag::findOrFail($this->editingTagId), $input);

        Flux::modal('tag-form')->close();
        Flux::toast(variant: 'success', text: __('Group saved. Your products were matched again.'));
        $this->resetTagForm();
    }

    public function deleteTag(DeleteIngredientTag $delete): void
    {
        $delete->handle(Auth::user(), IngredientTag::findOrFail($this->editingTagId));

        Flux::modal('tag-form')->close();
        Flux::toast(text: __('Group deleted.'));
        $this->resetTagForm();
    }

    public function createRule(): void
    {
        $this->resetValidation();
        $this->reset('tag_a_id', 'tag_b_id', 'scope', 'note');
        Flux::modal('rule-form')->show();
    }

    public function saveRule(CreateConflictRule $create): void
    {
        $this->resetValidation();

        $create->handle(Auth::user(), [
            'tag_a_id' => $this->tag_a_id,
            'tag_b_id' => $this->tag_b_id,
            'scope' => $this->scope,
            'note' => $this->note,
        ]);

        Flux::modal('rule-form')->close();
        Flux::toast(variant: 'success', text: __('Rule added.'));
        $this->reset('tag_a_id', 'tag_b_id', 'scope', 'note');
    }

    public function deleteRule(int $id, DeleteConflictRule $delete): void
    {
        $delete->handle(Auth::user(), ConflictRule::findOrFail($id));

        Flux::toast(text: __('Rule deleted.'));
    }

    private function resetTagForm(): void
    {
        $this->resetValidation();
        $this->reset('editingTagId', 'name', 'keywords');
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-care.tabs current="care.conflicts" />

    {{-- Rules --}}
    <section class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <flux:heading level="2" class="flex-1">{{ __('My rules') }}</flux:heading>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="createRule" data-test="add-rule-button">{{ __('Add rule') }}</flux:button>
        </header>
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->rules as $rule)
                <li wire:key="rule-{{ $rule->id }}" class="flex items-start gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1 text-sm">
                        <span class="font-medium">{{ $rule->tagA->name }} ✕ {{ $rule->tagB->name }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">· {{ __($rule->scope->label()) }}</span>
                        @if ($rule->note)<span class="block text-xs italic text-zinc-500 dark:text-zinc-400">“{{ $rule->note }}”</span>@endif
                    </div>
                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteRule({{ $rule->id }})" wire:confirm="{{ __('Delete this rule?') }}" :aria-label="__('Delete rule')" />
                </li>
            @empty
                <li class="px-4 py-6 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No rules yet. Write your own, e.g. "AHA ✕ Retinoid · in the same routine". Synop only warns you; it doesn\'t give skincare advice.') }}
                </li>
            @endforelse
        </ul>
    </section>

    {{-- Ingredient groups --}}
    <section class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <flux:heading level="2" class="flex-1">{{ __('Ingredient groups') }}</flux:heading>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="createTag" data-test="add-tag-button">{{ __('Add group') }}</flux:button>
        </header>
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->tags as $tag)
                <li wire:key="tag-{{ $tag->id }}">
                    <button type="button" wire:click="editTag({{ $tag->id }})" class="block w-full px-4 py-3 text-start">
                        <span class="flex items-center justify-between gap-3 text-sm font-medium">
                            {{ $tag->name }}
                            <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count product|:count products', $tag->products_count) }}</span>
                        </span>
                        <span class="mt-1 block break-words text-xs text-zinc-500 dark:text-zinc-400">{{ implode(', ', $tag->keywords) }}</span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No groups yet.') }}</li>
            @endforelse
        </ul>
    </section>

    {{-- Group window --}}
    <flux:modal name="tag-form" class="w-full max-w-md">
        <form wire:submit="saveTag" class="space-y-5">
            <flux:heading size="lg">{{ $editingTagId === null ? __('Add group') : __('Edit group') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. AHA')" required />
            <flux:textarea wire:model="keywords" :label="__('Ingredient names')" :description="__('One per line or separated by commas. A product joins the group when its ingredient list contains any of them.')" rows="5" />
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($editingTagId !== null)
                    <flux:button variant="danger" wire:click="deleteTag" wire:confirm="{{ __('Delete this group? Rules that use it are deleted too.') }}">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-tag-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Rule window --}}
    <flux:modal name="rule-form" class="w-full max-w-md">
        <form wire:submit="saveRule" class="space-y-5">
            <flux:heading size="lg">{{ __('Add rule') }}</flux:heading>
            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="tag_a_id" :label="__('Group')" :placeholder="__('Choose…')">
                    @foreach ($this->tags as $tag)
                        <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="tag_b_id" :label="__('Not with')" :placeholder="__('Choose…')">
                    @foreach ($this->tags as $tag)
                        <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:radio.group wire:model="scope" :label="__('When')">
                @foreach (ConflictScope::cases() as $option)
                    <flux:radio :value="$option->value" :label="__($option->label())" />
                @endforeach
            </flux:radio.group>
            <flux:input wire:model="note" :label="__('Your note')" :placeholder="__('Optional, shown in the warning')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-rule-button">{{ __('Add rule') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
