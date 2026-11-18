{{--
    The tab bar shared by the four Finance pages (D-041).
    Pages pass their own route name: the current request is Livewire's own
    URL after the first click, so it can't be read from request().
--}}
@props(['current'])

@php
    $tabs = [
        'finance.overview' => __('Overview'),
        'finance.accounts' => __('Accounts'),
        'finance.transactions' => __('Transactions'),
        'finance.debts' => __('Debts'),
    ];
@endphp

<div class="space-y-4">
    <flux:heading size="xl" level="1">{{ __('Finance') }}</flux:heading>

    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="{{ __('Finance sections') }}">
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
