{{--
    A list of open debts with a "Record payment" button each. Used by Finance → Overview
    and Today → Payments Due. Expects $debts (with paid_cents and contact loaded) and $today (Y-m-d).
--}}
<ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
    @foreach ($debts as $debt)
        @php
            $overdue = $debt->due_on->toDateString() < $today;
            $label = $debt->direction === \App\Enums\DebtDirection::OwedToMe
                ? __('Collect :amount from :name', ['amount' => money($debt->remaining()), 'name' => $debt->contact->name])
                : __('Pay :name :amount', ['name' => $debt->contact->name, 'amount' => money($debt->remaining())]);
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
                        ? __('Overdue · was due :date', ['date' => $debt->due_on->format('M j')])
                        : ($debt->due_on->toDateString() === $today ? __('Due today') : __('Due :date', ['date' => $debt->due_on->format('D, M j')])) }}
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
