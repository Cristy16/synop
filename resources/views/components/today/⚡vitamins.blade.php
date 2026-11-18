<?php

use App\Actions\PersonalCare\TakeVitaminDose;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleRule;
use App\Models\VitaminDose;
use App\Services\ScheduledItems;
use App\Support\ScheduledItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Today → Vitamins: today's doses in time order, each ticked (which takes the
 * tablets off the bottle) or skipped (D-047).
 */
new class extends Component {
    /**
     * @return list<ScheduledItem>
     */
    #[Computed]
    public function doses(): array
    {
        $user = Auth::user();
        $items = app(ScheduledItems::class)->on($user, now($user->timezone)->toDateString(), 'vitamin_dose')
            ->filter(fn (ScheduledItem $i) => $i->item instanceof VitaminDose);

        // A dose's product always belongs to the dose's owner.
        (new Collection($items->map(fn (ScheduledItem $i) => $i->item)->all()))
            ->load(['product' => fn ($q) => $q->withoutGlobalScope('user')]);

        return $items
            ->sortBy(fn (ScheduledItem $i) => substr((string) $i->item->getAttribute('dose_time'), 0, 5))
            ->values()
            ->all();
    }

    public function toggleDose(int $ruleId, TakeVitaminDose $take): void
    {
        $rule = $this->rule($ruleId);
        $done = $rule->logs()->whereDate('occurs_on', now(Auth::user()->timezone)->toDateString())->first()?->status === ScheduleLogStatus::Done;

        $take->handle(Auth::user(), $rule, $done ? null : ScheduleLogStatus::Done);
    }

    public function skipDose(int $ruleId, TakeVitaminDose $take): void
    {
        $take->handle(Auth::user(), $this->rule($ruleId), ScheduleLogStatus::Skipped);
    }

    public function unskipDose(int $ruleId, TakeVitaminDose $take): void
    {
        $take->handle(Auth::user(), $this->rule($ruleId), null);
    }

    /**
     * Another user's rule id isn't found: 404 (rule 11).
     */
    private function rule(int $ruleId): ScheduleRule
    {
        return ScheduleRule::where('schedulable_type', 'vitamin_dose')->findOrFail($ruleId);
    }
}; ?>

@php
    $counted = array_filter($this->doses, fn ($i) => $i->status() !== ScheduleLogStatus::Skipped);
    $done = count(array_filter($counted, fn ($i) => $i->status() === ScheduleLogStatus::Done));
@endphp

<x-today.section
    :title="__('Vitamins')"
    icon="beaker"
    :summary="count($counted) > 0 ? __(':done of :total done', ['done' => $done, 'total' => count($counted)]) : null"
    :empty="__('No vitamins to take today.')"
    :link="route('care.vitamins')"
    :link-label="__('Edit vitamins')"
>
    @if ($this->doses !== [])
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->doses as $item)
                @php
                    $dose = $item->item;
                    $product = $dose->product;
                    $isDone = $item->status() === ScheduleLogStatus::Done;
                    $isSkipped = $item->status() === ScheduleLogStatus::Skipped;
                @endphp
                <li wire:key="dose-{{ $item->rule->id }}" class="flex items-start gap-3 px-4 py-3">
                    <div class="pt-0.5">
                        <flux:checkbox :checked="$isDone" wire:click="toggleDose({{ $item->rule->id }})"
                            :aria-label="__('Took :name', ['name' => $product?->displayName()])" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <span @class([
                            'block break-words text-sm font-medium',
                            'text-zinc-400 line-through dark:text-zinc-500' => $isDone,
                            'text-zinc-400 dark:text-zinc-500' => $isSkipped,
                        ])>{{ $product?->displayName() }}</span>
                        <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $dose->timeLabel() }} · {{ trans_choice(':count tablet|:count tablets', $dose->units) }}</span>
                            @if ($dose->meal_note)<span>· {{ $dose->meal_note }}</span>@endif
                            @if ($product?->units_left !== null)<span data-test="today-units-left">· {{ __(':n left', ['n' => $product->units_left]) }}</span>@endif
                            @if ($isSkipped)
                                <span>{{ __('Skipped') }}</span>
                                <button type="button" wire:click="unskipDose({{ $item->rule->id }})" class="underline">{{ __('Undo') }}</button>
                            @elseif (! $isDone)
                                <button type="button" wire:click="skipDose({{ $item->rule->id }})" class="underline">{{ __('Skip') }}</button>
                            @endif
                        </span>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-today.section>
