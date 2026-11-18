<?php

use App\Enums\DebtDirection;
use App\Models\Account;
use App\Models\Debt;
use App\Services\AccountBalances;
use App\Services\DebtSummary;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Finance')] class extends Component {
    /**
     * @return array{balance: Money, owed_to_me: Money, i_owe: Money, net: Money}
     */
    #[Computed]
    public function figures(): array
    {
        $user = Auth::user();
        $balance = app(AccountBalances::class)->total($user);
        $debts = app(DebtSummary::class)->totals($user);

        return [
            'balance' => $balance,
            'owed_to_me' => $debts['owed_to_me'],
            'i_owe' => $debts['i_owe'],
            // What you'd have if every debt were settled today (D-041).
            'net' => $balance->add($debts['owed_to_me'])->subtract($debts['i_owe']),
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::orderBy('name')->get();
    }

    /**
     * @return array<int, Money>
     */
    #[Computed]
    public function balances(): array
    {
        return app(AccountBalances::class)->forUser(Auth::user());
    }

    /**
     * @return Collection<int, Debt>
     */
    #[Computed]
    public function dueSoon(): Collection
    {
        return app(DebtSummary::class)->dueSoon(Auth::user());
    }

    #[On('debt-paid')]
    public function refreshAfterPayment(): void
    {
        // Re-render with the new figures.
    }
}; ?>

@php
    $f = $this->figures;
    $today = now(auth()->user()->timezone)->toDateString();
    $cards = [
        ['label' => __('Total balance'), 'value' => $f['balance'], 'hint' => __('All your accounts'), 'test' => 'balance'],
        ['label' => __('Owed to me'), 'value' => $f['owed_to_me'], 'hint' => __('Still to collect'), 'test' => 'owed-to-me'],
        ['label' => __('I owe'), 'value' => $f['i_owe'], 'hint' => __('Still to pay'), 'test' => 'i-owe'],
        ['label' => __('Net'), 'value' => $f['net'], 'hint' => __('Balance + owed to me − I owe'), 'test' => 'net'],
    ];
@endphp

<div class="mx-auto w-full max-w-5xl space-y-6">
    <x-finance.tabs current="finance.overview" />

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="figure-{{ $card['test'] }}">
                <flux:text class="text-sm">{{ $card['label'] }}</flux:text>
                <div @class([
                    'mt-1 text-lg font-semibold tabular-nums sm:text-xl',
                    'text-red-600 dark:text-red-400' => $card['value']->isNegative(),
                ])>{{ money($card['value']) }}</div>
                <flux:text class="mt-1 text-xs">{{ $card['hint'] }}</flux:text>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-today.section :title="__('Accounts')" icon="wallet" :empty="__('No accounts yet.')" :link="route('finance.accounts')" :link-label="__('Manage accounts')">
            @if ($this->accounts->isNotEmpty())
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->accounts as $account)
                        @php $balance = $this->balances[$account->id] ?? Money::zero(); @endphp
                        <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                            <span class="min-w-0 truncate">{{ $account->name }}</span>
                            <span @class(['tabular-nums', 'text-red-600 dark:text-red-400' => $balance->isNegative()])>{{ money($balance) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-today.section>

        <x-today.section
            :title="__('Due soon')"
            icon="calendar"
            :summary="$this->dueSoon->isNotEmpty() ? trans_choice(':count due|:count due', $this->dueSoon->count()) : null"
            :empty="__('No debts due in the next :days days.', ['days' => auth()->user()->payments_due_days])"
            :link="route('finance.debts')"
            :link-label="__('See all debts')"
        >
            @if ($this->dueSoon->isNotEmpty())
                @include('partials.finance.due-list', ['debts' => $this->dueSoon, 'today' => $today])
            @endif
        </x-today.section>
    </div>

    <livewire:finance.record-payment />
</div>
