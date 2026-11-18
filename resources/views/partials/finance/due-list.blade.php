{{--
    A list of debts due, with a "Record payment" button each. Used by Finance → Overview
    and Today → Payments Due. Expects $items (list of App\Support\DueDebt) and $today (Y-m-d).
--}}
<ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
    @foreach ($items as $item)
        @php
            $debt = $item->debt;
            $date = \Carbon\CarbonImmutable::parse($item->date);
            $overdue = $item->date < $today;
            $label = $debt->direction === \App\Enums\DebtDirection::OwedToMe
                ? __('Collect :amount from :name', ['amount' => money($item->amount), 'name' => $debt->contact->name])
                : __('Pay :name :amount', ['name' => $debt->contact->name, 'amount' => money($item->amount)]);
        @endphp
        <li wire:key="due-{{ $debt->id }}" class="flex items-center gap-3 px-4 py-3">
            <div class="min-w-0 flex-1">
                <span class="block break-words text-sm font-medium">{{ $label }}</span>
                <span @class([
                    'text-xs',
                    'font-medium text-red-600 dark:text-red-400' => $overdue,
                    'text-zinc-500 dark:text-zinc-400' => ! $overdue,
                ]) data-test="{{ $overdue ? 'due-overdue' : 'due-date' }}">
                    {{ $overdue
                        ? __('Overdue · was due :date', ['date' => $date->format('M j')])
                        : ($item->date === $today ? __('Due today') : __('Due :date', ['date' => $date->format('D, M j')])) }}
                    @if ($item->installment)
                        · {{ __('payment :n of :count', ['n' => $item->installment->number, 'count' => $item->installment->count]) }}
                    @endif
                </span>
            </div>
            <flux:button
                size="sm"
                wire:click="$dispatch('record-payment', { debtId: {{ $debt->id }} })"
                data-test="record-payment-{{ $debt->id }}"
            >
                {{ __('Record payment') }}
            </flux:button>
        </li>
    @endforeach
</ul>
