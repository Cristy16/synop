{{--
    The tab bar shared by the School pages (D-048), with the semester picker.
    Pages pass their own route name and the semester shown; the picker sets the
    page's `semester` property (kept in the address bar).
--}}
@props(['current', 'semester' => null, 'semesters' => collect(), 'picker' => true])

@php
    $tabs = [
        'school.courses' => __('Courses'),
        'school.activities' => __('Activities'),
        'school.semesters' => __('Semesters'),
    ];
@endphp

<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('School') }}</flux:heading>
        @if ($picker && $semesters->isNotEmpty())
            <div class="w-full sm:w-64">
                <flux:select wire:model.live="semester" :aria-label="__('Semester')" data-test="semester-picker">
                    @foreach ($semesters as $option)
                        <flux:select.option :value="$option->id">
                            {{ $option->name }}@if ($option->is_active) · {{ __('active') }}@elseif ($option->isArchived()) · {{ __('archived') }}@endif
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="{{ __('School sections') }}">
        @foreach ($tabs as $route => $label)
            <flux:button
                size="sm"
                :variant="$route === $current ? 'primary' : 'ghost'"
                :href="route($route, $semester ? ['semester' => $semester->id] : [])"
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
