{{--
    Bottom navigation on phones (ARCHITECTURE §8, D-052). The sidebar stays on
    larger screens; settings are in the top-right menu.
--}}
@php
    $items = [
        ['today', 'today', 'sun', __('Today')],
        ['tasks.index', 'tasks.*', 'check-circle', __('Tasks')],
        ['finance.overview', 'finance.*', 'banknotes', __('Money')],
        ['wants.index', 'wants.*', 'gift', __('Wants')],
        ['care.products', 'care.*', 'sparkles', __('Care')],
        ['school.courses', 'school.*', 'academic-cap', __('School')],
    ];
@endphp

<nav
    class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95"
    aria-label="{{ __('Main') }}"
    data-test="bottom-nav"
>
    <ul class="grid grid-cols-6">
        @foreach ($items as [$route, $pattern, $icon, $label])
            @php $active = request()->routeIs($pattern); @endphp
            <li>
                <a
                    href="{{ route($route) }}"
                    wire:navigate
                    @if ($active) aria-current="page" @endif
                    @class([
                        'flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] font-medium',
                        'text-zinc-900 dark:text-white' => $active,
                        'text-zinc-500 dark:text-zinc-400' => ! $active,
                    ])
                >
                    <flux:icon :name="$icon" :variant="$active ? 'solid' : 'outline'" class="size-6" />
                    <span>{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
