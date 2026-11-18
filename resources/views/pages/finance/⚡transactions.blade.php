<?php

use App\Actions\Finance\DeleteTransaction;
use App\Actions\Finance\RecordTransaction;
use App\Actions\Finance\UpdateTransaction;
use App\Enums\CategoryKind;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Services\TransactionList;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Transactions')] class extends Component {
    use WithPagination;

    // Filters, kept in the address bar.
    #[Url]
    public string $month = '';
    #[Url]
    public string $account = '';
    #[Url]
    public string $type = '';
    #[Url]
    public string $category = '';

    /** The transaction in the edit window; null when recording a new one. */
    #[Locked]
    public ?int $editingId = null;

    public string $form_type = 'expense';
    public string $account_id = '';
    public string $to_account_id = '';
    public string $category_id = '';
    public string $amount = '';
    public string $occurred_on = '';
    public string $note = '';

    public function mount(TransactionList $list): void
    {
        $this->month = $list->month(Auth::user(), $this->month);
    }

    /**
     * Any filter change starts again from page 1.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'account', 'type', 'category'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Transaction>
     */
    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        return app(TransactionList::class)->for(Auth::user(), [
            'month' => $this->month,
            'account_id' => $this->account,
            'type' => $this->type,
            'category_id' => $this->category,
        ], $this->getPage());
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
     * @return Collection<int, TransactionCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return TransactionCategory::orderBy('kind')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->occurred_on = now(Auth::user()->timezone)->toDateString();
        $this->account_id = (string) ($this->account !== '' ? $this->account : ($this->accounts->first()?->id ?? ''));

        Flux::modal('transaction-form')->show();
    }

    public function edit(int $id): void
    {
        $transaction = Transaction::findOrFail($id);

        $this->resetForm();

        if ($transaction->hasSource()) {
            Flux::toast(text: match ($transaction->source_type) {
                'product' => __('This one was made by a product. Change it in Personal Care.'),
                'want' => __('This one was made by a want. Change it on the Wants page.'),
                default => __('This one was made by a debt. Change it on the Debts tab.'),
            });

            return;
        }

        $this->editingId = $transaction->id;
        $this->form_type = $transaction->type->value;
        $this->account_id = (string) $transaction->account_id;
        $this->to_account_id = (string) ($transaction->to_account_id ?? '');
        $this->category_id = (string) ($transaction->category_id ?? '');
        $this->amount = $transaction->amount_cents->toDecimalString();
        $this->occurred_on = $transaction->occurred_on->toDateString();
        $this->note = $transaction->note ?? '';

        Flux::modal('transaction-form')->show();
    }

    /**
     * A category of the other kind no longer fits when the type changes.
     */
    public function updatedFormType(): void
    {
        $this->category_id = '';
        $this->to_account_id = '';
    }

    public function save(RecordTransaction $recordTransaction, UpdateTransaction $updateTransaction): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $type = TransactionType::tryFrom($this->form_type);

        $input = [
            'type' => $this->form_type,
            'account_id' => $this->account_id,
            'to_account_id' => $type === TransactionType::Transfer ? $this->to_account_id : null,
            'category_id' => $type === TransactionType::Transfer ? null : $this->category_id,
            'amount' => $this->amount,
            'occurred_on' => $this->occurred_on,
            'note' => $this->note === '' ? null : $this->note,
        ];

        try {
            $this->editingId === null
                ? $recordTransaction->handle(Auth::user(), $input)
                : $updateTransaction->handle(Auth::user(), Transaction::findOrFail($this->editingId), $input);
        } catch (ValidationException $e) {
            // The Action says "type"; on this page that name belongs to the filter.
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => [$key === 'type' ? 'form_type' : $key => $messages])->all()
            );
        }

        Flux::modal('transaction-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Transaction saved.') : __('Transaction updated.'));
        $this->resetForm();
    }

    public function delete(DeleteTransaction $deleteTransaction): void
    {
        $deleteTransaction->handle(Auth::user(), Transaction::findOrFail($this->editingId));

        Flux::modal('transaction-form')->close();
        Flux::toast(text: __('Transaction deleted.'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'form_type', 'account_id', 'to_account_id', 'category_id', 'amount', 'occurred_on', 'note');
    }
}; ?>

@php
    $formType = TransactionType::tryFrom($form_type);
    $formKind = $formType?->categoryKind();
@endphp

<div class="mx-auto w-full max-w-4xl space-y-6">
    <x-finance.tabs current="finance.transactions" />

    {{-- Filters --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <flux:input wire:model.live="month" type="month" :label="__('Month')" />

        <flux:select wire:model.live="account" :label="__('Account')">
            <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
            @foreach ($this->accounts as $option)
                <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="type" :label="__('Type')">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (TransactionType::cases() as $option)
                <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="category" :label="__('Category')">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categories as $option)
                <flux:select.option :value="$option->id">{{ $option->name }} ({{ __($option->kind->label()) }})</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex justify-end">
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-transaction-button">{{ __('Record transaction') }}</flux:button>
    </div>

    {{-- List --}}
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->transactions as $transaction)
            @php
                $in = in_array($transaction->type, [TransactionType::Income, TransactionType::DebtIn], true)
                    || ($transaction->type === TransactionType::Transfer && $this->account !== '' && (string) $transaction->to_account_id === $this->account);
                $sign = $transaction->type === TransactionType::Transfer && $this->account === '' ? '' : ($in ? '+' : '−');
            @endphp
            <li wire:key="transaction-{{ $transaction->id }}">
                <button type="button" wire:click="edit({{ $transaction->id }})" class="flex w-full items-start gap-3 px-4 py-3 text-start">
                    <div class="min-w-0 flex-1">
                        <span class="block break-words text-sm font-medium">
                            @if ($transaction->type === TransactionType::Transfer)
                                {{ $transaction->account->name }} → {{ $transaction->toAccount?->name }}
                            @else
                                {{ $transaction->category?->name ?? __($transaction->type->label()) }}
                            @endif
                        </span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ $transaction->occurred_on->format('D, M j') }}</span>
                            @if ($transaction->type !== TransactionType::Transfer)
                                <span>· {{ $transaction->account->name }}</span>
                            @endif
                            @if ($transaction->note)
                                <span class="break-words">· {{ $transaction->note }}</span>
                            @endif
                            @if ($transaction->hasSource())
                                <flux:badge size="sm" color="blue">{{ match ($transaction->source_type) { 'product' => __('From product'), 'want' => __('From want'), default => __('From debt') } }}</flux:badge>
                            @endif
                        </span>
                    </div>
                    <span @class([
                        'shrink-0 tabular-nums text-sm font-medium',
                        'text-green-600 dark:text-green-400' => $sign === '+',
                    ])>{{ $sign }}{{ money($transaction->amount_cents) }}</span>
                </button>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-state">
                {{ __('No transactions for these filters.') }}
            </li>
        @endforelse
    </ul>

    <flux:pagination :paginator="$this->transactions" />

    {{-- Record / edit window --}}
    <flux:modal name="transaction-form" class="w-full max-w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Record transaction') : __('Edit transaction') }}</flux:heading>

            @if ($this->accounts->isEmpty())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('Add an account first.') }}
                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('finance.accounts')" wire:navigate>{{ __('Add an account') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @else
                <flux:select wire:model.live="form_type" :label="__('Type')">
                    @foreach (TransactionType::manual() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="amount" :label="__('Amount')" inputmode="decimal" placeholder="0.00" required />

                <flux:select wire:model="account_id" :label="$formType === TransactionType::Income ? __('Into') : __('From')">
                    @foreach ($this->accounts as $option)
                        <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($formType === TransactionType::Transfer)
                    <flux:select wire:model="to_account_id" :label="__('To')" :placeholder="__('Choose an account')">
                        @foreach ($this->accounts as $option)
                            <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @elseif ($formKind)
                    <flux:select wire:model="category_id" :label="__('Category')" :placeholder="__('Choose a category')">
                        @foreach ($this->categories->where('kind', $formKind) as $option)
                            <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:input wire:model="occurred_on" type="date" :label="__('Date')" required />

                <flux:input wire:model="note" :label="__('Note')" :placeholder="__('Optional')" />
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingId !== null)
                    <flux:button variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this transaction?') }}" data-test="delete-transaction-button">
                        {{ __('Delete') }}
                    </flux:button>
                @endif

                <div class="ms-auto flex gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    @if ($this->accounts->isNotEmpty())
                        <flux:button type="submit" variant="primary" data-test="save-transaction-button">{{ __('Save') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
    </flux:modal>
</div>
