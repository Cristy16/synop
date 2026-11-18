<?php

use App\Models\Debt;
use App\Services\DebtSummary;
use Illuminate\Database\Eloquent\Collection;
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
     * @return Collection<int, Debt>
     */
    #[Computed]
    public function debts(): Collection
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
    :summary="$this->debts->isNotEmpty() ? trans_choice(':count due|:count due', $this->debts->count()) : null"
    :empty="__('Nothing due in the next :days days.', ['days' => auth()->user()->payments_due_days])"
    :link="route('finance.debts')"
    :link-label="__('See all debts')"
>
    @if ($this->debts->isNotEmpty())
        @include('partials.finance.due-list', ['debts' => $this->debts, 'today' => now(auth()->user()->timezone)->toDateString()])
    @endif
</x-today.section>
