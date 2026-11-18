<?php

use App\Actions\Finance\CreateAccount;
use App\Actions\Finance\DeleteAccount;
use App\Actions\Finance\UpdateAccount;
use App\Enums\AccountType;
use App\Models\Account;
use App\Services\AccountBalances;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Accounts')] class extends Component {
    /** The account in the edit window; null when adding. Locked against tampering. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $type = 'cash';
    public string $opening_balance = '';

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

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('account-form')->show();
    }

    public function edit(int $id): void
    {
        $account = Account::findOrFail($id);

        $this->resetForm();
        $this->editingId = $account->id;
        $this->name = $account->name;
        $this->type = $account->type->value;
        $this->opening_balance = $account->opening_balance_cents->toDecimalString();

        Flux::modal('account-form')->show();
    }

    public function save(CreateAccount $createAccount, UpdateAccount $updateAccount): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $input = ['name' => trim($this->name), 'type' => $this->type, 'opening_balance' => $this->opening_balance];

        $this->editingId === null
            ? $createAccount->handle(Auth::user(), $input)
            : $updateAccount->handle(Auth::user(), Account::findOrFail($this->editingId), $input);

        Flux::modal('account-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Account added.') : __('Account saved.'));
        $this->resetForm();
    }

    public function delete(DeleteAccount $deleteAccount): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        try {
            $deleteAccount->handle(Auth::user(), Account::findOrFail($this->editingId));
        } catch (AuthorizationException $e) {
            // Another user's record stays a 404; "money left" is shown to the user.
            if ($e->status() !== null) {
                throw $e;
            }

            $this->addError('delete', $e->getMessage());

            return;
        }

        Flux::modal('account-form')->close();
        Flux::toast(text: __('Account deleted.'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'type', 'opening_balance');
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-finance.tabs current="finance.accounts" />

    <div class="flex items-center justify-between gap-3">
        <div>
            <flux:text class="text-sm">{{ __('Total') }}</flux:text>
            <div class="text-lg font-semibold tabular-nums" data-test="accounts-total">
                {{ money(array_reduce($this->balances, fn ($sum, $b) => $sum->add($b), Money::zero())) }}
            </div>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-account-button">{{ __('Add account') }}</flux:button>
    </div>

    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->accounts as $account)
            @php $balance = $this->balances[$account->id] ?? Money::zero(); @endphp
            <li wire:key="account-{{ $account->id }}">
                <button type="button" wire:click="edit({{ $account->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start">
                    <div class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ $account->name }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __($account->type->label()) }}</span>
                    </div>
                    <span @class(['tabular-nums text-sm font-medium', 'text-red-600 dark:text-red-400' => $balance->isNegative()])>
                        {{ money($balance) }}
                    </span>
                </button>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('No accounts yet. Add your wallet, e-wallet or bank account to start.') }}
            </li>
        @endforelse
    </ul>

    <flux:modal name="account-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Add account') : __('Edit account') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. GCash, BPI Savings')" required />

            <flux:select wire:model="type" :label="__('Type')">
                @foreach (AccountType::cases() as $option)
                    <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="opening_balance"
                :label="__('Opening balance')"
                :description="__('How much was in it when you started using Synop.')"
                inputmode="decimal"
                placeholder="0.00"
            />

            <flux:error name="delete" />

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingId !== null)
                    <flux:button variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this account?') }}" data-test="delete-account-button">
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <div class="ms-auto flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-account-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
