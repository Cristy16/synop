{{-- Pairs with the ScansIngredientLabels trait. --}}
<div class="space-y-1">
    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
        <flux:icon name="camera" variant="mini" />
        <span>{{ __('Or read them from a label photo') }}</span>
        <input type="file" wire:model="labelPhoto" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" data-test="label-photo">
    </label>
    <div wire:loading wire:target="labelPhoto" class="text-xs text-zinc-500">{{ __('Reading the label…') }}</div>
    <flux:error name="labelPhoto" />
    <flux:text class="text-xs">{{ __('The photo is sent to Google Gemini once to read it, then deleted. Check the text before saving.') }}</flux:text>
</div>
