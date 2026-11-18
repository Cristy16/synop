<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="pb-24 lg:pb-8">
        <x-install-hint />
        {{ $slot }}
    </flux:main>

    <x-bottom-nav />
</x-layouts::app.sidebar>
