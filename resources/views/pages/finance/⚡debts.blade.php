<?php

use App\Actions\Finance\CreateContact;
use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteContact;
use App\Actions\Finance\DeleteDebt;
use App\Actions\Finance\DeleteDebtPayment;
use App\Actions\Finance\UpdateContact;
use App\Actions\Finance\UpdateDebt;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Services\DebtSummary;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Debts')] class extends Component {
    #[Url(as: 'settled')]
    public bool $showSettled = false;

    /** The debt whose payments are listed under it. */
    public ?int $showPaymentsFor = null;

    // Contact window. Ids are locked: the browser can't swap in another record.
    #[Locked]
    public ?int $editingContactId = null;
    public string $contact_name = '';
    public string $contact_note = '';

    // Debt window.
    #[Locked]
    public ?int $editingDebtId = null;
    public string $contact_id = '';
    public string $direction = 'owed_to_me';
    public string $principal = '';
    public string $description = '';
    public string $account_id = '';
    public string $started_on = '';
    public string $due_on = '';

    // Delete-contact window.
    #[Locked]
    public ?int $deletingContactId = null;
    public string $confirm_name = '';

    /**
     * @return list<array{contact: Contact, debts: Collection<int, Debt>, net: \App\Support\Money}>
     */
    #[Computed]
    public function groups(): array
    {
        return app(DebtSummary::class)->byContact(Auth::user(), $this->showSettled);
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
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return Contact::orderBy('name')->get();
    }

    /**
     * @return Collection<int, DebtPayment>
     */
    #[Computed]
    public function payments(): Collection
    {
        return $this->showPaymentsFor === null
            ? new Collection
            : DebtPayment::with('account')->where('debt_id', $this->showPaymentsFor)->latest('paid_on')->latest('id')->get();
    }

    #[Computed]
    public function deletingContact(): ?Contact
    {
        return $this->deletingContactId === null ? null : Contact::find($this->deletingContactId);
    }

    #[Computed]
    public function deletingHasOpenDebts(): bool
    {
        return $this->deletingContactId !== null
            && Debt::where('contact_id', $this->deletingContactId)->where('status', DebtStatus::Open->value)->exists();
    }

    #[On('debt-paid')]
    public function refreshAfterPayment(): void
    {
        // Re-render with the new remaining amounts.
    }

    // Contacts

    public function createContact(): void
    {
        $this->resetContactForm();
        Flux::modal('contact-form')->show();
    }

    public function editContact(int $id): void
    {
        $contact = Contact::findOrFail($id);

        $this->resetContactForm();
        $this->editingContactId = $contact->id;
        $this->contact_name = $contact->name;
        $this->contact_note = $contact->note ?? '';

        Flux::modal('contact-form')->show();
    }

    public function saveContact(CreateContact $createContact, UpdateContact $updateContact): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $input = ['name' => $this->contact_name, 'note' => $this->contact_note === '' ? null : $this->contact_note];

        $this->withFieldNames(['name' => 'contact_name', 'note' => 'contact_note'], fn () => $this->editingContactId === null
            ? $createContact->handle(Auth::user(), $input)
            : $updateContact->handle(Auth::user(), Contact::findOrFail($this->editingContactId), $input));

        Flux::modal('contact-form')->close();
        Flux::toast(variant: 'success', text: __('Contact saved.'));
        $this->resetContactForm();
    }

    public function askDeleteContact(int $id): void
    {
        $contact = Contact::findOrFail($id);

        $this->resetValidation();
        $this->deletingContactId = $contact->id;
        $this->confirm_name = '';

        Flux::modal('contact-form')->close();
        Flux::modal('delete-contact')->show();
    }

    public function deleteContact(DeleteContact $deleteContact): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $deleteContact->handle(Auth::user(), Contact::findOrFail($this->deletingContactId), $this->confirm_name);

        Flux::modal('delete-contact')->close();
        Flux::toast(text: __('Contact deleted.'));
        $this->reset('deletingContactId', 'confirm_name');
    }

    // Debts

    public function createDebt(?int $contactId = null): void
    {
        $this->resetDebtForm();
        $this->contact_id = (string) ($contactId !== null ? Contact::findOrFail($contactId)->id : '');
        $this->started_on = now(Auth::user()->timezone)->toDateString();

        Flux::modal('debt-form')->show();
    }

    public function editDebt(int $id): void
    {
        $debt = Debt::findOrFail($id);

        $this->resetDebtForm();
        $this->editingDebtId = $debt->id;
        $this->contact_id = (string) $debt->contact_id;
        $this->direction = $debt->direction->value;
        $this->principal = $debt->principal_cents->toDecimalString();
        $this->description = $debt->description ?? '';
        $this->account_id = (string) ($debt->account_id ?? '');
        $this->started_on = $debt->started_on->toDateString();
        $this->due_on = $debt->due_on?->toDateString() ?? '';

        Flux::modal('debt-form')->show();
    }

    public function saveDebt(CreateDebt $createDebt, UpdateDebt $updateDebt): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $input = [
            'contact_id' => $this->contact_id,
            'direction' => $this->direction,
            'principal' => $this->principal,
            'description' => $this->description === '' ? null : $this->description,
            'account_id' => $this->account_id === '' ? null : $this->account_id,
            'started_on' => $this->started_on,
            'due_on' => $this->due_on === '' ? null : $this->due_on,
        ];

        $this->editingDebtId === null
            ? $createDebt->handle(Auth::user(), $input)
            : $updateDebt->handle(Auth::user(), Debt::findOrFail($this->editingDebtId), $input);

        Flux::modal('debt-form')->close();
        Flux::toast(variant: 'success', text: __('Debt saved.'));
        $this->resetDebtForm();
    }

    public function deleteDebt(DeleteDebt $deleteDebt): void
    {
        $deleteDebt->handle(Auth::user(), Debt::findOrFail($this->editingDebtId));

        Flux::modal('debt-form')->close();
        Flux::toast(text: __('Debt deleted.'));
        $this->resetDebtForm();
    }

    // Payments

    public function togglePayments(int $debtId): void
    {
        $this->showPaymentsFor = $this->showPaymentsFor === $debtId ? null : Debt::withTrashed()->findOrFail($debtId)->id;
    }

    public function deletePayment(int $id, DeleteDebtPayment $deleteDebtPayment): void
    {
        $deleteDebtPayment->handle(Auth::user(), DebtPayment::findOrFail($id));

        Flux::toast(text: __('Payment deleted.'));
    }

    // Helpers

    /**
     * Run an Action and rename its error keys to this page's field names.
     *
     * @param  array<string, string>  $names
     */
    private function withFieldNames(array $names, Closure $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => [$names[$key] ?? $key => $messages])->all()
            );
        }
    }

    private function resetContactForm(): void
    {
        $this->resetValidation();
        $this->reset('editingContactId', 'contact_name', 'contact_note');
    }

    private function resetDebtForm(): void
    {
        $this->resetValidation();
        $this->reset('editingDebtId', 'contact_id', 'direction', 'principal', 'description', 'account_id', 'started_on', 'due_on');
    }
}; ?>

@php $today = now(auth()->user()->timezone)->toDateString(); @endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-finance.tabs current="finance.debts" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:switch wire:model.live="showSettled" :label="__('Show settled')" align="left" />
        <div class="flex gap-2">
            <flux:button icon="user-plus" wire:click="createContact" data-test="add-contact-button">{{ __('Add contact') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="createDebt" data-test="add-debt-button">{{ __('Add debt') }}</flux:button>
        </div>
    </div>

    @forelse ($this->groups as $group)
        @php $contact = $group['contact']; $net = $group['net']; @endphp
        <section wire:key="contact-{{ $contact->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="contact-group">
            <header class="flex items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <button type="button" wire:click="editContact({{ $contact->id }})" class="min-w-0 flex-1 text-start">
                    <flux:heading level="2" class="truncate">{{ $contact->name }}</flux:heading>
                    <flux:text class="text-xs" data-test="contact-net">
                        @if ($net->isZero())
                            {{ __('All square') }}
                        @elseif ($net->isNegative())
                            {{ __('You owe :name :amount', ['name' => $contact->name, 'amount' => money(\App\Support\Money::zero()->subtract($net))]) }}
                        @else
                            {{ __(':name owes you :amount', ['name' => $contact->name, 'amount' => money($net)]) }}
                        @endif
                    </flux:text>
                </button>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="createDebt({{ $contact->id }})" :aria-label="__('Add a debt with :name', ['name' => $contact->name])" />
            </header>

            @if ($group['debts']->isEmpty())
                <flux:text class="px-4 py-4 text-sm">{{ $showSettled ? __('No debts.') : __('No open debts.') }}</flux:text>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($group['debts'] as $debt)
                        @php
                            $settled = $debt->status === DebtStatus::Settled;
                            $overdue = ! $settled && $debt->due_on && $debt->due_on->toDateString() < $today;
                        @endphp
                        <li wire:key="debt-{{ $debt->id }}" class="px-4 py-3">
                            <div class="flex items-start gap-3">
                                <button type="button" wire:click="editDebt({{ $debt->id }})" class="min-w-0 flex-1 text-start">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" :color="$debt->direction === DebtDirection::OwedToMe ? 'green' : 'amber'">
                                            {{ __($debt->direction->label()) }}
                                        </flux:badge>
                                        <span @class(['text-sm font-medium tabular-nums', 'text-zinc-400 line-through dark:text-zinc-500' => $settled])>
                                            {{ __(':remaining of :principal', ['remaining' => money($debt->remaining()), 'principal' => money($debt->principal_cents)]) }}
                                        </span>
                                        @if ($settled)
                                            <flux:badge size="sm" color="zinc">{{ __('Settled') }}</flux:badge>
                                        @endif
                                    </span>
                                    <span class="mt-1 flex flex-wrap gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        @if ($debt->description)<span class="break-words">{{ $debt->description }}</span>@endif
                                        @if ($debt->due_on && ! $settled)
                                            <span @class(['font-medium text-red-600 dark:text-red-400' => $overdue])>
                                                {{ $overdue ? __('Overdue · was due :date', ['date' => $debt->due_on->format('M j')]) : __('Due :date', ['date' => $debt->due_on->format('D, M j')]) }}
                                            </span>
                                        @endif
                                    </span>
                                </button>

                                <div class="flex shrink-0 gap-1">
                                    @if (! $settled)
                                        <flux:button size="sm" wire:click="$dispatch('record-payment', { debtId: {{ $debt->id }} })" data-test="record-payment-{{ $debt->id }}">
                                            {{ __('Pay') }}
                                        </flux:button>
                                    @endif
                                    <flux:button size="sm" variant="ghost" :icon="$showPaymentsFor === $debt->id ? 'chevron-up' : 'chevron-down'" wire:click="togglePayments({{ $debt->id }})" :aria-label="__('Payments')" />
                                </div>
                            </div>

                            @if ($showPaymentsFor === $debt->id)
                                <ul class="mt-3 space-y-2 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-900" data-test="payments-list">
                                    @forelse ($this->payments as $payment)
                                        <li wire:key="payment-{{ $payment->id }}" class="flex items-center gap-2">
                                            <span class="flex-1">
                                                {{ $payment->paid_on->format('M j, Y') }} · {{ $payment->account->name }}
                                                @if ($payment->note)<span class="text-zinc-500"> · {{ $payment->note }}</span>@endif
                                            </span>
                                            <span class="tabular-nums">{{ money($payment->amount_cents) }}</span>
                                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deletePayment({{ $payment->id }})" wire:confirm="{{ __('Delete this payment? Its money movement is removed too.') }}" :aria-label="__('Delete payment')" />
                                        </li>
                                    @empty
                                        <li class="text-zinc-500">{{ __('No payments yet.') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @empty
        <div class="rounded-xl border border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __('No contacts yet. Add the people you lend to or borrow from.') }}
        </div>
    @endforelse

    {{-- Contact window --}}
    <flux:modal name="contact-form" class="w-full max-w-md">
        <form wire:submit="saveContact" class="space-y-5">
            <flux:heading size="lg">{{ $editingContactId === null ? __('Add contact') : __('Edit contact') }}</flux:heading>
            <flux:input wire:model="contact_name" :label="__('Name')" required />
            <flux:input wire:model="contact_note" :label="__('Note')" :placeholder="__('Optional')" />
            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingContactId !== null)
                    <flux:button variant="danger" wire:click="askDeleteContact({{ $editingContactId }})" data-test="delete-contact-button">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Delete-contact window (D-040) --}}
    <flux:modal name="delete-contact" class="w-full max-w-md">
        @if ($this->deletingContact)
            @php $name = $this->deletingContact->name; @endphp
            <form wire:submit="deleteContact" class="space-y-5">
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $name]) }}</flux:heading>

                @if ($this->deletingHasOpenDebts)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        {{ __(':name still has open debts. Deleting will also remove ALL their debts, payments and money movements, as if they never existed. Your balances will change. You can restore them later.', ['name' => $name]) }}
                    </flux:callout>
                    <flux:input wire:model="confirm_name" :label="__('Type :name to confirm', ['name' => $name])" autocomplete="off" data-test="confirm-name-input" />
                @else
                    <flux:text>{{ __(':name will be hidden. Settled debts stay in your history.', ['name' => $name]) }}</flux:text>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" data-test="confirm-delete-contact-button">{{ __('Delete') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Debt window --}}
    <flux:modal name="debt-form" class="w-full max-w-md">
        <form wire:submit="saveDebt" class="space-y-5">
            <flux:heading size="lg">{{ $editingDebtId === null ? __('Add debt') : __('Edit debt') }}</flux:heading>

            @if ($this->contacts->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">{{ __('Add a contact first.') }}</flux:callout>
            @else
                <flux:select wire:model="contact_id" :label="__('Contact')" :placeholder="__('Choose a contact')">
                    @foreach ($this->contacts as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:radio.group wire:model="direction" :label="__('Who owes whom?')">
                    <flux:radio value="owed_to_me" :label="__('They owe me (I lent money)')" />
                    <flux:radio value="i_owe" :label="__('I owe them (I borrowed money)')" />
                </flux:radio.group>

                <flux:input wire:model="principal" :label="__('Amount')" inputmode="decimal" placeholder="0.00" required />
                <flux:input wire:model="description" :label="__('What for')" :placeholder="__('Optional, e.g. Concert ticket')" />

                <flux:select wire:model="account_id" :label="__('Account')" :description="__('Where the money left or came in. Leave empty for a debt from before Synop.')">
                    <flux:select.option value="">{{ __('No account (no money moved)') }}</flux:select.option>
                    @foreach ($this->accounts as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="started_on" type="date" :label="__('Date')" required />
                    <flux:input wire:model="due_on" type="date" :label="__('Due')" :description="__('Optional')" />
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingDebtId !== null)
                    <flux:button variant="danger" wire:click="deleteDebt" wire:confirm="{{ __('Delete this debt? Its payments and money movements are removed too.') }}" data-test="delete-debt-button">
                        {{ __('Delete') }}
                    </flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    @if ($this->contacts->isNotEmpty())
                        <flux:button type="submit" variant="primary" data-test="save-debt-button">{{ __('Save') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>

    <livewire:finance.record-payment />
</div>
