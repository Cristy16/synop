<?php

use App\Actions\PersonalCare\AddVitaminDose;
use App\Actions\PersonalCare\LowStockReminder;
use App\Actions\PersonalCare\RemoveVitaminDose;
use App\Actions\PersonalCare\RestockVitamin;
use App\Actions\PersonalCare\UpdateVitaminDose;
use App\Enums\Frequency;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ScheduleRule;
use App\Models\VitaminDose;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Vitamins')] class extends Component {
    // Dose window. Ids are locked against tampering.
    #[Locked]
    public ?int $productId = null;
    #[Locked]
    public ?int $editingDoseId = null;
    public string $dose_time = '08:00';
    public string $units = '1';
    public string $meal_note = '';
    public string $frequency = 'daily';
    public string $interval = '2';
    /** @var list<string> */
    public array $weekdays = [];
    public string $day_of_month = '';

    // Restock window.
    #[Locked]
    public ?int $restockId = null;
    public string $restock_units = '';

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function vitamins(): Collection
    {
        return Product::where('type', ProductType::Vitamin->value)
            ->with('doses')
            ->orderByRaw("case when status in ('unopened', 'in_use') then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * Each dose's current schedule, keyed by dose id.
     *
     * @return \Illuminate\Support\Collection<int, ScheduleRule>
     */
    #[Computed]
    public function schedules(): \Illuminate\Support\Collection
    {
        $today = now(Auth::user()->timezone)->toDateString();

        return ScheduleRule::where('schedulable_type', 'vitamin_dose')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')
            ->get()
            ->keyBy('schedulable_id');
    }

    public function addDose(int $productId): void
    {
        $this->resetDoseForm();
        $this->productId = Product::where('type', ProductType::Vitamin->value)->findOrFail($productId)->id;

        Flux::modal('dose-form')->show();
    }

    public function editDose(int $id): void
    {
        $dose = VitaminDose::findOrFail($id);
        $rule = $dose->currentRule();

        $this->resetDoseForm();
        $this->editingDoseId = $dose->id;
        $this->productId = $dose->product_id;
        $this->dose_time = substr($dose->dose_time, 0, 5);
        $this->units = (string) $dose->units;
        $this->meal_note = $dose->meal_note ?? '';

        if ($rule !== null) {
            $this->frequency = $rule->frequency->value;
            $this->interval = (string) ($rule->interval ?? 2);
            $this->weekdays = array_map('strval', $rule->weekdays ?? []);
            $this->day_of_month = (string) ($rule->day_of_month ?? '');
        }

        Flux::modal('dose-form')->show();
    }

    public function saveDose(AddVitaminDose $add, UpdateVitaminDose $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $frequency = Frequency::tryFrom($this->frequency);
        $input = [
            'product_id' => $this->productId,
            'dose_time' => $this->dose_time,
            'units' => $this->units,
            'meal_note' => $this->meal_note,
            'frequency' => $this->frequency,
            'interval' => $frequency === Frequency::EveryNDays ? $this->interval : null,
            'weekdays' => $frequency === Frequency::Weekly ? array_map('intval', $this->weekdays) : null,
            'day_of_month' => $frequency === Frequency::Monthly ? $this->day_of_month : null,
        ];

        try {
            $this->editingDoseId === null
                ? $add->handle(Auth::user(), $input)
                : $update->handle(Auth::user(), VitaminDose::findOrFail($this->editingDoseId), $input);
        } catch (ValidationException $e) {
            // "weekdays.0" belongs to the weekdays field; a schedule overlap shows on "how often".
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                fn (array $messages, string $key) => [match (explode('.', $key)[0]) { 'starts_on' => 'frequency', default => explode('.', $key)[0] } => $messages]
            )->all());
        }

        Flux::modal('dose-form')->close();
        Flux::toast(variant: 'success', text: __('Dose saved.'));
        $this->resetDoseForm();
    }

    public function removeDose(int $id, RemoveVitaminDose $remove): void
    {
        $remove->handle(Auth::user(), VitaminDose::findOrFail($id));

        Flux::toast(text: __('Dose removed.'));
    }

    public function restock(int $productId): void
    {
        $this->resetValidation();
        $this->restockId = Product::where('type', ProductType::Vitamin->value)->findOrFail($productId)->id;
        $this->restock_units = '';

        Flux::modal('restock-form')->show();
    }

    public function saveRestock(RestockVitamin $restock): void
    {
        $this->resetValidation();

        $product = $restock->handle(Auth::user(), Product::findOrFail($this->restockId), $this->restock_units);

        Flux::modal('restock-form')->close();
        Flux::toast(variant: 'success', text: __(':name now has :count left.', ['name' => $product->displayName(), 'count' => $product->units_left]));
        $this->reset('restockId', 'restock_units');
    }

    private function resetDoseForm(): void
    {
        $this->resetValidation();
        $this->reset('productId', 'editingDoseId', 'dose_time', 'units', 'meal_note', 'frequency', 'interval', 'weekdays', 'day_of_month');
    }
}; ?>

@php $repeat = Frequency::tryFrom($frequency); @endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-care.tabs current="care.vitamins" />

    @forelse ($this->vitamins as $vitamin)
        @php $low = LowStockReminder::isProductLow($vitamin); @endphp
        <section wire:key="vitamin-{{ $vitamin->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="vitamin">
            <header class="flex flex-wrap items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div class="min-w-0 flex-1">
                    <flux:heading level="2" class="truncate">{{ $vitamin->displayName() }}</flux:heading>
                    <flux:text class="text-xs" data-test="units-left">
                        @if ($vitamin->units_left === null)
                            {{ __('Tablets not counted. Set "Tablets left" on the product to track them.') }}
                        @else
                            {{ trans_choice(':count tablet left|:count tablets left', $vitamin->units_left) }}
                            @if ($vitamin->low_stock_threshold !== null) · {{ __('remind me at :n', ['n' => $vitamin->low_stock_threshold]) }}@endif
                        @endif
                    </flux:text>
                </div>
                @if ($low)
                    <flux:badge size="sm" color="amber" data-test="low-stock">{{ __('Running low') }}</flux:badge>
                @endif
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="restock({{ $vitamin->id }})" data-test="restock-{{ $vitamin->id }}">{{ __('Add tablets') }}</flux:button>
            </header>

            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($vitamin->doses as $dose)
                    @php $rule = $this->schedules->get($dose->id); @endphp
                    <li wire:key="dose-{{ $dose->id }}" class="flex items-start gap-3 px-4 py-3">
                        <button type="button" wire:click="editDose({{ $dose->id }})" class="min-w-0 flex-1 text-start">
                            <span class="block text-sm font-medium">
                                {{ $dose->timeLabel() }} · {{ trans_choice(':count tablet|:count tablets', $dose->units) }}
                            </span>
                            <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>↻ {{ $rule?->summary() ?? __('Not scheduled') }}</span>
                                @if ($dose->meal_note)<span>· {{ $dose->meal_note }}</span>@endif
                            </span>
                        </button>
                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeDose({{ $dose->id }})" wire:confirm="{{ __('Remove this dose? Its history is kept.') }}" :aria-label="__('Remove dose')" />
                    </li>
                @endforeach
                <li class="px-4 py-2">
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addDose({{ $vitamin->id }})" data-test="add-dose-{{ $vitamin->id }}">{{ __('Add a dose time') }}</flux:button>
                </li>
            </ul>
        </section>
    @empty
        <div class="rounded-xl border border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __('No vitamins yet. Add one on the Products tab (type: Vitamins).') }}
            <div class="mt-3"><flux:button size="sm" :href="route('care.products')" wire:navigate>{{ __('Go to Products') }}</flux:button></div>
        </div>
    @endforelse

    {{-- Dose window --}}
    <flux:modal name="dose-form" class="w-full max-w-md">
        <form wire:submit="saveDose" class="space-y-5">
            <flux:heading size="lg">{{ $editingDoseId === null ? __('Add a dose time') : __('Edit dose') }}</flux:heading>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="dose_time" type="time" :label="__('Time')" required />
                <flux:input wire:model="units" type="number" min="1" max="20" :label="__('Tablets')" />
            </div>
            <flux:input wire:model="meal_note" :label="__('With meals')" :placeholder="__('Optional, e.g. after breakfast')" />

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

            <flux:error name="product_id" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-dose-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Restock window --}}
    <flux:modal name="restock-form" class="w-full max-w-sm">
        <form wire:submit="saveRestock" class="space-y-5">
            <flux:heading size="lg">{{ __('Add tablets') }}</flux:heading>
            <flux:input wire:model="restock_units" type="number" min="1" :label="__('How many?')" :placeholder="__('e.g. 60 for a new bottle')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-restock-button">{{ __('Add') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
