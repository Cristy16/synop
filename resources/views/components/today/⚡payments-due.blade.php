<?php

use App\Services\DebtSummary;
use App\Support\DueDebt;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Today → Payments Due: open debts both ways, due within the user's window
 * (payments_due_days) or overdue (PRD §2.2, D-009, D-041).
 */
new class extends Component {
    /**
     * @return list<DueDebt>
     */
    #[Computed]
    public function debts(): array
    {
        return app(DebtSummary::class)->dueSoon(Auth::user());
    }

    #[On('debt-paid')]
    public function refreshAfterPayment(): void
    {
        // Re-render: a paid-off debt leaves the list.
    }
}; ?>

<x-today.section
    :title="__('Payments Due')"
    icon="banknotes"
    :summary="count($this->debts) > 0 ? trans_choice(':count due|:count due', count($this->debts)) : null"
    :empty="__('Nothing due in the next :days days.', ['days' => auth()->user()->payments_due_days])"
    :link="route('finance.debts')"
    :link-label="__('See all debts')"
>
    @if (count($this->debts) > 0)
        @include('partials.finance.due-list', ['items' => $this->debts, 'today' => now(auth()->user()->timezone)->toDateString()])
    @endif
</x-today.section>
