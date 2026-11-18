<?php

use App\Actions\Finance\RecordDebtPayment;
use App\Enums\DebtDirection;
use App\Models\Account;
use App\Models\Debt;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Record payment" window. Any page opens it with:
 *     $dispatch('record-payment', { debtId: 12 })
 * and hears 'debt-paid' after a payment is saved (D-041).
 */
new class extends Component {
    /** The debt being paid. Locked: the browser can't swap in another id. */
    #[Locked]
    public ?int $debtId = null;

    public string $account_id = '';
    public string $amount = '';
    public string $paid_on = '';
    public string $note = '';

    #[On('record-payment')]
    public function open(int $debtId): void
    {
        // Another user's debt isn't found: 404 (rule 11).
        $debt = Debt::findOrFail($debtId);

        $this->resetValidation();
        $this->debtId = $debt->id;
        $this->amount = $debt->remaining()->toDecimalString();
        $this->paid_on = now(Auth::user()->timezone)->toDateString();
        $this->note = '';

        $ownAccount = $debt->account_id !== null && $this->accounts->contains('id', $debt->account_id);
        $this->account_id = (string) ($ownAccount ? $debt->account_id : ($this->accounts->first()?->id ?? ''));

        Flux::modal('record-payment')->show();
    }

    #[Computed]
    public function debt(): ?Debt
    {
        return $this->debtId === null ? null : Debt::with('contact')->find($this->debtId);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::orderBy('name')->get();
    }

    public function save(RecordDebtPayment $recordDebtPayment): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $debt = Debt::findOrFail($this->debtId);

        $recordDebtPayment->handle(Auth::user(), $debt, [
            'account_id' => $this->account_id,
            'amount' => $this->amount,
            'paid_on' => $this->paid_on,
            'note' => $this->note === '' ? null : $this->note,
        ]);

        $settled = $debt->fresh()?->isSettled();

        Flux::modal('record-payment')->close();
        $this->reset('debtId', 'account_id', 'amount', 'paid_on', 'note');
        $this->dispatch('debt-paid');

        Flux::toast(variant: 'success', text: $settled ? __('Payment saved. The debt is settled.') : __('Payment saved.'));
    }
}; ?>

<div>
    <flux:modal name="record-payment" class="w-full max-w-md">
        @if ($this->debt)
            @php $debt = $this->debt; @endphp
            <form wire:submit="save" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Record payment') }}</flux:heading>
                    <flux:text class="mt-1" data-test="payment-summary">
                        @if ($debt->direction === DebtDirection::OwedToMe)
                            {{ __(':name pays you back · :remaining left', ['name' => $debt->contact->name, 'remaining' => money($debt->remaining())]) }}
                        @else
                            {{ __('You pay :name · :remaining left', ['name' => $debt->contact->name, 'remaining' => money($debt->remaining())]) }}
                        @endif
                    </flux:text>
                </div>

                @if ($this->accounts->isEmpty())
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        {{ __('Add an account first, so the payment has somewhere to go.') }}
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('finance.accounts')" wire:navigate>{{ __('Add an account') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @else
                    <flux:input wire:model="amount" :label="__('Amount')" inputmode="decimal" required data-test="payment-amount" />

                    <flux:select wire:model="account_id" :label="$debt->direction === DebtDirection::OwedToMe ? __('Paid into') : __('Paid from')">
                        @foreach ($this->accounts as $account)
                            <flux:select.option :value="$account->id">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="paid_on" type="date" :label="__('Date')" required />

                    <flux:input wire:model="note" :label="__('Note')" :placeholder="__('Optional')" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if ($this->accounts->isNotEmpty())
                        <flux:button type="submit" variant="primary" data-test="save-payment-button">{{ __('Save payment') }}</flux:button>
                    @endif
                </div>
            </form>
        @endif
    </flux:modal>
</div>
