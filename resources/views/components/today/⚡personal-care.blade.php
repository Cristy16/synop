<?php

use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\UnmarkOccurrence;
use App\Enums\RoutinePeriod;
use App\Enums\ScheduleLogStatus;
use App\Models\RoutineStep;
use App\Models\ScheduleRule;
use App\Services\ConflictChecker;
use App\Services\ScheduledItems;
use App\Support\Clash;
use App\Support\ScheduledItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Today → Personal Care: today's AM and PM routine steps (each on its own
 * schedule), ticked or skipped one by one, and warnings for broken conflict
 * rules (D-046). Missed days don't carry over (D-042).
 */
new class extends Component {
    /**
     * Today's steps, grouped AM then PM, in routine order.
     *
     * @return array<string, list<ScheduledItem>>
     */
    #[Computed]
    public function groups(): array
    {
        $user = Auth::user();
        $items = app(ScheduledItems::class)->on($user, now($user->timezone)->toDateString(), 'routine_step')
            ->filter(fn (ScheduledItem $i) => $i->item instanceof RoutineStep);

        // A step's routine and product always belong to the step's owner.
        (new Collection($items->map(fn (ScheduledItem $i) => $i->item)->all()))->load([
            'routine' => fn ($q) => $q->withoutGlobalScope('user'),
            'product' => fn ($q) => $q->withoutGlobalScope('user'),
        ]);

        $groups = [RoutinePeriod::Am->value => [], RoutinePeriod::Pm->value => []];

        foreach ($items->sortBy(fn (ScheduledItem $i) => $i->item->getAttribute('position')) as $item) {
            $period = $item->item->getRelationValue('routine')?->period?->value;

            if ($period !== null) {
                $groups[$period][] = $item;
            }
        }

        return $groups;
    }

    /**
     * @return list<Clash>
     */
    #[Computed]
    public function clashes(): array
    {
        $user = Auth::user();

        return app(ConflictChecker::class)->onDate($user, now($user->timezone)->toDateString());
    }

    public function toggleStep(int $ruleId): void
    {
        [$rule, $today] = $this->ruleAndToday($ruleId);
        $done = $rule->logs()->whereDate('occurs_on', $today)->first()?->status === ScheduleLogStatus::Done;

        $done
            ? app(UnmarkOccurrence::class)->handle(Auth::user(), $rule, $today)
            : app(MarkOccurrence::class)->handle(Auth::user(), $rule, $today, ScheduleLogStatus::Done);
    }

    public function skipStep(int $ruleId): void
    {
        [$rule, $today] = $this->ruleAndToday($ruleId);

        app(MarkOccurrence::class)->handle(Auth::user(), $rule, $today, ScheduleLogStatus::Skipped);
    }

    public function unskipStep(int $ruleId): void
    {
        [$rule, $today] = $this->ruleAndToday($ruleId);

        app(UnmarkOccurrence::class)->handle(Auth::user(), $rule, $today);
    }

    /**
     * Another user's rule id isn't found: 404 (rule 11).
     *
     * @return array{0: ScheduleRule, 1: string}
     */
    private function ruleAndToday(int $ruleId): array
    {
        $rule = ScheduleRule::where('schedulable_type', 'routine_step')->findOrFail($ruleId);

        return [$rule, now(Auth::user()->timezone)->toDateString()];
    }
}; ?>

@php
    $all = array_merge(...array_values($this->groups));
    $counted = array_filter($all, fn ($i) => $i->status() !== ScheduleLogStatus::Skipped);
    $done = count(array_filter($counted, fn ($i) => $i->status() === ScheduleLogStatus::Done));
@endphp

<x-today.section
    :title="__('Personal Care')"
    icon="sparkles"
    :summary="count($counted) > 0 ? __(':done of :total done', ['done' => $done, 'total' => count($counted)]) : null"
    :empty="__('No routine steps today.')"
    :link="route('care.routines')"
    :link-label="__('Edit routines')"
>
    @if ($all !== [])
        @if ($this->clashes !== [])
            <div class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-700/60 dark:bg-amber-950/30" data-test="conflict-warnings">
                <p class="flex items-center gap-2 font-medium text-amber-800 dark:text-amber-300">
                    <flux:icon name="exclamation-triangle" variant="mini" />
                    {{ trans_choice('Today breaks :count of your rules|Today breaks :count of your rules', count($this->clashes)) }}
                </p>
                <ul class="mt-1 space-y-1 text-amber-900 dark:text-amber-200">
                    @foreach ($this->clashes as $clash)
                        <li>
                            {{ $clash->summary() }}
                            @if ($clash->rule->note)<span class="italic">— “{{ $clash->rule->note }}”</span>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($this->groups as $period => $items)
            @if ($items !== [])
                <div class="border-b border-zinc-200 px-4 pt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 last:border-b-0 dark:border-zinc-700 dark:text-zinc-400">
                    {{ RoutinePeriod::from($period)->label() }}
                </div>
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($items as $item)
                        @php
                            $step = $item->item;
                            $isDone = $item->status() === ScheduleLogStatus::Done;
                            $isSkipped = $item->status() === ScheduleLogStatus::Skipped;
                        @endphp
                        <li wire:key="step-{{ $item->rule->id }}" class="flex items-start gap-3 px-4 py-3">
                            <div class="pt-0.5">
                                <flux:checkbox :checked="$isDone" wire:click="toggleStep({{ $item->rule->id }})"
                                    :aria-label="__('Done: :name', ['name' => $step->product?->displayName()])" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <span @class([
                                    'block break-words text-sm font-medium',
                                    'text-zinc-400 line-through dark:text-zinc-500' => $isDone,
                                    'text-zinc-400 dark:text-zinc-500' => $isSkipped,
                                ])>{{ $step->product?->displayName() }}</span>
                                <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($step->note)<span>{{ $step->note }}</span>@endif
                                    @if ($isSkipped)
                                        <span>{{ __('Skipped') }}</span>
                                        <button type="button" wire:click="unskipStep({{ $item->rule->id }})" class="underline">{{ __('Undo') }}</button>
                                    @elseif (! $isDone)
                                        <button type="button" wire:click="skipStep({{ $item->rule->id }})" class="underline">{{ __('Skip') }}</button>
                                    @endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    @endif
</x-today.section>
