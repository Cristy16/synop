<?php

use App\Models\ClassSession;
use App\Services\ClassSchedule;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Today → Classes: today's classes from every semester that isn't archived,
 * in time order; the one happening now is marked (D-048).
 */
new class extends Component {
    /**
     * @return list<ClassSession>
     */
    #[Computed]
    public function sessions(): array
    {
        $user = Auth::user();

        return app(ClassSchedule::class)->on($user, now($user->timezone)->toDateString());
    }
}; ?>

@php $now = now(auth()->user()->timezone)->format('H:i'); @endphp

<x-today.section
    :title="__('Classes')"
    icon="academic-cap"
    :summary="count($this->sessions) > 0 ? trans_choice(':count class|:count classes', count($this->sessions)) : null"
    :empty="__('No classes today.')"
    :link="route('school.courses')"
    :link-label="__('See courses')"
>
    @if ($this->sessions !== [])
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->sessions as $session)
                @php
                    $course = $session->course;
                    $isNow = substr($session->start_time, 0, 5) <= $now && $now < substr($session->end_time, 0, 5);
                    $room = $session->roomOr($course);
                @endphp
                <li wire:key="class-{{ $session->id }}" @class(['flex items-start gap-3 px-4 py-3', 'bg-zinc-50 dark:bg-white/5' => $isNow])>
                    <span class="w-28 shrink-0 text-xs font-medium tabular-nums text-zinc-500 dark:text-zinc-400">{{ $session->timeRange() }}</span>
                    <div class="min-w-0 flex-1">
                        <span class="block break-words text-sm font-medium">{{ $course?->displayName() }}</span>
                        <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($room)<span>{{ __('Rm :room', ['room' => $room]) }}</span>@endif
                            @if ($session->label)<span>· {{ $session->label }}</span>@endif
                            @if ($course?->instructor)<span>· {{ $course->instructor }}</span>@endif
                        </span>
                    </div>
                    @if ($isNow)
                        <flux:badge size="sm" color="green" data-test="class-now">{{ __('Now') }}</flux:badge>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</x-today.section>
