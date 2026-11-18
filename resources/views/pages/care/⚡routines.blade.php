<?php

use App\Actions\PersonalCare\AddRoutineStep;
use App\Actions\PersonalCare\MoveRoutineStep;
use App\Actions\PersonalCare\RemoveRoutineStep;
use App\Actions\PersonalCare\UpdateRoutineStep;
use App\Enums\Frequency;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\Routine;
use App\Models\RoutineStep;
use App\Models\ScheduleRule;
use App\Services\ConflictChecker;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Routines')] class extends Component {
    /** The routine a new step goes into. Ids are locked against tampering. */
    #[Locked]
    public ?int $routineId = null;

    /** The step in the edit window; null when adding. */
    #[Locked]
    public ?int $editingStepId = null;

    public string $product_id = '';
    public string $note = '';
    public string $frequency = 'daily';
    public string $interval = '2';
    /** @var list<string> */
    public array $weekdays = [];
    public string $day_of_month = '';

    /** Clashes found after the last save, shown until dismissed (D-046). @var list<array{summary: string, note: string|null, when: string}> */
    public array $warnings = [];

    /**
     * @return Collection<int, Routine>
     */
    #[Computed]
    public function routines(): Collection
    {
        return Routine::with('steps.product')->orderBy('period')->get();
    }

    /**
     * Each step's current schedule, keyed by step id.
     *
     * @return \Illuminate\Support\Collection<int, ScheduleRule>
     */
    #[Computed]
    public function schedules(): \Illuminate\Support\Collection
    {
        $today = now(Auth::user()->timezone)->toDateString();

        return ScheduleRule::where('schedulable_type', 'routine_step')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')
            ->get()
            ->keyBy('schedulable_id');
    }

    /**
     * Skincare and makeup products that can be steps; ones still in use first.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        return Product::whereIn('type', [ProductType::Skincare->value, ProductType::Makeup->value])
            ->orderByRaw("case when status in ('unopened', 'in_use') then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }

    public function addStep(int $routineId): void
    {
        $this->resetForm();
        $this->routineId = Routine::findOrFail($routineId)->id;

        Flux::modal('step-form')->show();
    }

    public function editStep(int $id): void
    {
        $step = RoutineStep::findOrFail($id);
        $rule = $step->currentRule();

        $this->resetForm();
        $this->editingStepId = $step->id;
        $this->routineId = $step->routine_id;
        $this->product_id = (string) $step->product_id;
        $this->note = $step->note ?? '';

        if ($rule !== null) {
            $this->frequency = $rule->frequency->value;
            $this->interval = (string) ($rule->interval ?? 2);
            $this->weekdays = array_map('strval', $rule->weekdays ?? []);
            $this->day_of_month = (string) ($rule->day_of_month ?? '');
        }

        Flux::modal('step-form')->show();
    }

    public function saveStep(AddRoutineStep $addStep, UpdateRoutineStep $updateStep, ConflictChecker $checker): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $frequency = Frequency::tryFrom($this->frequency);
        $input = [
            'product_id' => $this->product_id,
            'note' => $this->note,
            'frequency' => $this->frequency,
            'interval' => $frequency === Frequency::EveryNDays ? $this->interval : null,
            'weekdays' => $frequency === Frequency::Weekly ? array_map('intval', $this->weekdays) : null,
            'day_of_month' => $frequency === Frequency::Monthly ? $this->day_of_month : null,
        ];

        try {
            $step = $this->editingStepId === null
                ? $addStep->handle(Auth::user(), Routine::findOrFail($this->routineId), $input)
                : $updateStep->handle(Auth::user(), RoutineStep::findOrFail($this->editingStepId), $input);
        } catch (ValidationException $e) {
            // "weekdays.0" belongs to the weekdays field; a schedule overlap shows on "how often".
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                fn (array $messages, string $key) => [match (explode('.', $key)[0]) { 'starts_on' => 'frequency', default => explode('.', $key)[0] } => $messages]
            )->all());
        }

        // Saved either way; warn about clashes in the next 4 weeks (D-046).
        $this->warnings = array_map(fn (array $found) => [
            'summary' => $found['clash']->summary(),
            'note' => $found['clash']->rule->note,
            'when' => $this->describeDates($found['dates']),
        ], $checker->upcomingFor(Auth::user(), $step));

        Flux::modal('step-form')->close();
        Flux::toast(variant: $this->warnings === [] ? 'success' : 'warning', text: $this->warnings === [] ? __('Step saved.') : __('Step saved, with a clash. See the warning.'));
        $this->resetForm();
    }

    public function moveStep(int $id, int $direction, MoveRoutineStep $move): void
    {
        $move->handle(Auth::user(), RoutineStep::findOrFail($id), $direction);
    }

    public function removeStep(int $id, RemoveRoutineStep $remove): void
    {
        $remove->handle(Auth::user(), RoutineStep::findOrFail($id));

        Flux::toast(text: __('Step removed.'));
    }

    public function dismissWarnings(): void
    {
        $this->warnings = [];
    }

    /**
     * "on Tue, Sat (next: Nov 24)", or "every day (next: Nov 19)".
     *
     * @param  list<string>  $dates
     */
    private function describeDates(array $dates): string
    {
        $days = collect($dates)->map(fn (string $d) => \Carbon\CarbonImmutable::parse($d))
            ->sortBy(fn ($d) => $d->isoWeekday())
            ->map(fn ($d) => $d->format('D'))
            ->unique()
            ->values();

        $next = \Carbon\CarbonImmutable::parse($dates[0])->format('M j');

        return $days->count() === 7
            ? __('every day (next: :date)', ['date' => $next])
            : __('on :days (next: :date)', ['days' => $days->implode(', '), 'date' => $next]);
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('routineId', 'editingStepId', 'product_id', 'note', 'frequency', 'interval', 'weekdays', 'day_of_month');
    }
}; ?>

@php $repeat = Frequency::tryFrom($frequency); @endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-care.tabs current="care.routines" />

    {{-- Clash warning after saving a step (D-046) --}}
    @if ($warnings !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="save-warnings">
            <flux:callout.heading>{{ __('Saved, but this clashes with your rules') }}</flux:callout.heading>
            <flux:callout.text>
                <ul class="mt-1 space-y-1">
                    @foreach ($warnings as $warning)
                        <li>
                            {{ $warning['summary'] }}, {{ $warning['when'] }}
                            @if ($warning['note'])<span class="italic">— “{{ $warning['note'] }}”</span>@endif
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
            <x-slot name="controls">
                <flux:button icon="x-mark" variant="ghost" size="sm" wire:click="dismissWarnings" :aria-label="__('Dismiss')" />
            </x-slot>
        </flux:callout>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($this->routines as $routine)
            <section wire:key="routine-{{ $routine->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="routine-{{ $routine->period->value }}">
                <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:icon :name="$routine->period->value === 'am' ? 'sun' : 'moon'" variant="mini" class="text-zinc-500" />
                    <flux:heading level="2" class="flex-1">{{ $routine->name }}</flux:heading>
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addStep({{ $routine->id }})" data-test="add-step-{{ $routine->period->value }}">
                        {{ __('Add step') }}
                    </flux:button>
                </header>

                <ol class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($routine->steps as $step)
                        @php $rule = $this->schedules->get($step->id); @endphp
                        <li wire:key="step-{{ $step->id }}" class="flex items-start gap-3 px-4 py-3">
                            <span class="mt-0.5 w-5 text-sm tabular-nums text-zinc-400">{{ $loop->iteration }}</span>
                            <button type="button" wire:click="editStep({{ $step->id }})" class="min-w-0 flex-1 text-start">
                                <span class="block break-words text-sm font-medium">{{ $step->product?->displayName() }}</span>
                                <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>↻ {{ $rule?->summary() ?? __('Not scheduled') }}@if ($rule?->isPaused()) · {{ __('Paused') }}@endif</span>
                                    @if ($step->note)<span>· {{ $step->note }}</span>@endif
                                </span>
                            </button>
                            <div class="flex shrink-0">
                                <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveStep({{ $step->id }}, -1)" :disabled="$loop->first" :aria-label="__('Move up')" />
                                <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveStep({{ $step->id }}, 1)" :disabled="$loop->last" :aria-label="__('Move down')" />
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $step->id }})" wire:confirm="{{ __('Remove this step? Its history is kept.') }}" :aria-label="__('Remove step')" />
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No steps yet.') }}</li>
                    @endforelse
                </ol>
            </section>
        @endforeach
    </div>

    {{-- Step window --}}
    <flux:modal name="step-form" class="w-full max-w-md">
        <form wire:submit="saveStep" class="space-y-5">
            <flux:heading size="lg">{{ $editingStepId === null ? __('Add step') : __('Edit step') }}</flux:heading>

            @if ($this->products->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('Add a skincare or makeup product first.') }}
                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('care.products')" wire:navigate>{{ __('Go to Products') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @else
                <flux:select wire:model="product_id" :label="__('Product')" :placeholder="__('Choose a product')">
                    @foreach ($this->products as $option)
                        <flux:select.option :value="$option->id">
                            {{ $option->displayName() }}@if (! $option->status->isActive()) ({{ __($option->status->label()) }})@endif
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="note" :label="__('Note')" :placeholder="__('Optional, e.g. wait 10 minutes')" />

                <flux:select wire:model.live="frequency" :label="__('How often')">
                    @foreach (Frequency::cases() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($repeat === Frequency::EveryNDays)
                    <flux:input wire:model="interval" type="number" min="2" max="365" :label="__('Every how many days?')" />
                @elseif ($repeat === Frequency::Weekly)
                    <flux:checkbox.group wire:model="weekdays" :label="__('On')" class="flex flex-wrap gap-x-4 gap-y-2">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $value => $name)
                            <flux:checkbox :value="(string) $value" :label="__($name)" />
                        @endforeach
                    </flux:checkbox.group>
                @elseif ($repeat === Frequency::Monthly)
                    <flux:input wire:model="day_of_month" type="number" min="1" max="31" :label="__('Day of the month')" />
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                @if ($this->products->isNotEmpty())
                    <flux:button type="submit" variant="primary" data-test="save-step-button">{{ __('Save') }}</flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</div>
