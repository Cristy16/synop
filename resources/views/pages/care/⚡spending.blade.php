<?php

use App\Enums\ProductType;
use App\Services\CareSummary;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Personal care spending')] class extends Component {
    /**
     * @return list<array{month: string, types: array<string, Money>, total: Money}>
     */
    #[Computed]
    public function months(): array
    {
        return app(CareSummary::class)->monthlySpending(Auth::user());
    }
}; ?>

@php
    $months = $this->months;
    $thisMonth = $months[0];
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-care.tabs current="care.spending" />

    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="this-month">
        <flux:text class="text-sm">{{ __('This month') }}</flux:text>
        <div class="mt-1 text-xl font-semibold tabular-nums">{{ money($thisMonth['total']) }}</div>
        <flux:text class="mt-1 text-xs">
            @foreach (ProductType::cases() as $type)
                {{ __($type->label()) }} {{ money($thisMonth['types'][$type->value]) }}@if (! $loop->last) · @endif
            @endforeach
        </flux:text>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-200 text-start text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 text-start font-medium">{{ __('Month') }}</th>
                    @foreach (ProductType::cases() as $type)
                        <th class="px-4 py-2 text-end font-medium">{{ __($type->label()) }}</th>
                    @endforeach
                    <th class="px-4 py-2 text-end font-medium">{{ __('Total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($months as $row)
                    <tr wire:key="month-{{ $row['month'] }}">
                        <td class="whitespace-nowrap px-4 py-2">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $row['month'])->format('M Y') }}</td>
                        @foreach (ProductType::cases() as $type)
                            <td class="px-4 py-2 text-end tabular-nums text-zinc-600 dark:text-zinc-300">{{ money($row['types'][$type->value]) }}</td>
                        @endforeach
                        <td class="px-4 py-2 text-end font-medium tabular-nums">{{ money($row['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <flux:text class="text-xs">{{ __('Each product counts in the month it was bought, at the price entered for it.') }}</flux:text>
</div>
