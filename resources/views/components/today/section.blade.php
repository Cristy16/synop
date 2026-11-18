{{--
    The card every Today section uses, so they all look alike.
    Pass content in the slot; with an empty slot the `empty` message shows instead.
--}}
@props([
    'title',
    'icon',
    'summary' => null,
    'empty' => null,
    'link' => null,
    'linkLabel' => null,
])

<section {{ $attributes->class('rounded-xl border border-zinc-200 dark:border-zinc-700') }}>
    <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <flux:icon :name="$icon" variant="mini" class="text-zinc-500 dark:text-zinc-400" />
        <flux:heading level="2" class="flex-1">{{ $title }}</flux:heading>
        @if ($summary)
            <flux:text class="text-xs tabular-nums" data-test="section-summary">{{ $summary }}</flux:text>
        @endif
    </header>

    @if (! $slot->hasActualContent())
        <flux:text class="px-4 py-6 text-center text-sm" data-test="section-empty">{{ $empty }}</flux:text>
    @else
        {{ $slot }}
    @endif

    @if ($link)
        <footer class="border-t border-zinc-200 px-4 py-2 dark:border-zinc-700">
            <flux:link :href="$link" wire:navigate class="text-sm">{{ $linkLabel }}</flux:link>
        </footer>
    @endif
</section>
