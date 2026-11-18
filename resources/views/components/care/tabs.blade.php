{{--
    The tab bar shared by the Personal Care pages (D-045). Routines (6.2) and
    Vitamins (6.3) join it later. Pages pass their own route name, as in Finance.
--}}
@props(['current'])

@php
    $tabs = [
        'care.products' => __('Products'),
        'care.spending' => __('Spending'),
    ];
@endphp

<div class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Personal Care') }}</flux:heading>

    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="{{ __('Personal Care sections') }}">
        @foreach ($tabs as $route => $label)
            <flux:button
                size="sm"
                :variant="$route === $current ? 'primary' : 'ghost'"
                :href="route($route)"
                wire:navigate
                class="shrink-0"
                :aria-current="$route === $current ? 'page' : null"
                data-test="tab-{{ $route }}"
            >
                {{ $label }}
            </flux:button>
        @endforeach
    </nav>
</div>
