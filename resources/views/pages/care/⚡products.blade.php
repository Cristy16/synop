<?php

use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\DeleteProduct;
use App\Actions\PersonalCare\ProductBookkeeping;
use App\Actions\PersonalCare\UpdateProduct;
use App\Enums\CategoryKind;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Account;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Services\CareSummary;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Personal Care')] class extends Component {
    // Filters, kept in the address bar.
    #[Url]
    public string $type = '';
    #[Url(as: 'all')]
    public bool $showAll = false;

    /** The product in the edit window; null when adding. Locked against tampering. */
    #[Locked]
    public ?int $editingId = null;

    public string $form_type = 'skincare';
    public string $brand = '';
    public string $name = '';
    public string $category = '';
    public string $price = '';
    public string $purchased_on = '';
    public string $opened_on = '';
    public string $pao_months = '';
    public string $printed_expiry_on = '';
    public string $quantity = '1';
    public string $status = 'unopened';
    public string $ingredients_text = '';
    public string $units_left = '';
    public string $low_stock_threshold = '';
    public string $notes = '';
    public bool $record_expense = false;
    public string $account_id = '';
    public string $category_id = '';

    /** The product being deleted. */
    #[Locked]
    public ?int $deletingId = null;

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        $type = ProductType::tryFrom($this->type);

        return Product::query()
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type?->value))
            ->when(! $this->showAll, fn (Builder $q) => $q->whereIn('status', [ProductStatus::Unopened->value, ProductStatus::InUse->value]))
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function expiringSoon(): Collection
    {
        return app(CareSummary::class)->expiringSoon(Auth::user());
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
    public function expenseCategories(): Collection
    {
        return TransactionCategory::where('kind', CategoryKind::Expense->value)->orderBy('name')->get();
    }

    #[Computed]
    public function deleting(): ?Product
    {
        return $this->deletingId === null ? null : Product::find($this->deletingId);
    }

    #[Computed]
    public function deletingExpense(): ?Transaction
    {
        return $this->deleting === null ? null : app(ProductBookkeeping::class)->expenseOf($this->deleting);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->form_type = ProductType::tryFrom($this->type)?->value ?? 'skincare';
        $this->purchased_on = now(Auth::user()->timezone)->toDateString();

        Flux::modal('product-form')->show();
    }

    public function edit(int $id): void
    {
        $product = Product::findOrFail($id);
        $expense = app(ProductBookkeeping::class)->expenseOf($product);

        $this->resetForm();
        $this->editingId = $product->id;
        $this->form_type = $product->type->value;
        $this->brand = $product->brand ?? '';
        $this->name = $product->name;
        $this->category = $product->category ?? '';
        $this->price = $product->price_cents?->toDecimalString() ?? '';
        $this->purchased_on = $product->purchased_on?->toDateString() ?? '';
        $this->opened_on = $product->opened_on?->toDateString() ?? '';
        $this->pao_months = (string) ($product->pao_months ?? '');
        $this->printed_expiry_on = $product->printed_expiry_on?->toDateString() ?? '';
        $this->quantity = (string) $product->quantity;
        $this->status = $product->status->value;
        $this->ingredients_text = $product->ingredients_text ?? '';
        $this->units_left = (string) ($product->units_left ?? '');
        $this->low_stock_threshold = (string) ($product->low_stock_threshold ?? '');
        $this->notes = $product->notes ?? '';
        $this->record_expense = $expense !== null;
        $this->account_id = (string) ($expense->account_id ?? $this->defaultAccountId());
        $this->category_id = (string) ($expense->category_id ?? $this->defaultCategoryId());

        Flux::modal('product-form')->show();
    }

    public function save(CreateProduct $createProduct, UpdateProduct $updateProduct): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $vitamin = $this->form_type === ProductType::Vitamin->value;
        $input = [
            'type' => $this->form_type,
            'brand' => $this->brand,
            'name' => $this->name,
            'category' => $this->category,
            'price' => $this->price,
            'purchased_on' => $this->purchased_on === '' ? null : $this->purchased_on,
            'opened_on' => $this->opened_on === '' ? null : $this->opened_on,
            'pao_months' => $this->pao_months === '' ? null : $this->pao_months,
            'printed_expiry_on' => $this->printed_expiry_on === '' ? null : $this->printed_expiry_on,
            'quantity' => $this->quantity === '' ? null : $this->quantity,
            'status' => $this->status,
            'ingredients_text' => $this->ingredients_text,
            'units_left' => $vitamin && $this->units_left !== '' ? $this->units_left : null,
            'low_stock_threshold' => $vitamin && $this->low_stock_threshold !== '' ? $this->low_stock_threshold : null,
            'notes' => $this->notes,
            'record_expense' => $this->record_expense,
            'account_id' => $this->record_expense ? $this->account_id : null,
            'category_id' => $this->record_expense ? $this->category_id : null,
        ];

        $this->editingId === null
            ? $createProduct->handle(Auth::user(), $input)
            : $updateProduct->handle(Auth::user(), Product::findOrFail($this->editingId), $input);

        Flux::modal('product-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Product added.') : __('Product saved.'));
        $this->resetForm();
    }

    public function askDelete(int $id): void
    {
        $this->deletingId = Product::findOrFail($id)->id;

        Flux::modal('product-form')->close();
        Flux::modal('delete-product')->show();
    }

    /**
     * @param  bool  $putMoneyBack  the answer to "was this a mistake?" (only asked when there's an expense)
     */
    public function delete(DeleteProduct $deleteProduct, bool $putMoneyBack = false): void
    {
        $deleteProduct->handle(Auth::user(), Product::findOrFail($this->deletingId), $putMoneyBack);

        Flux::modal('delete-product')->close();
        Flux::toast(text: $putMoneyBack ? __('Product deleted. The money is back in the account.') : __('Product deleted.'));
        $this->reset('deletingId');
        $this->resetForm();
    }

    private function defaultAccountId(): string
    {
        return (string) ($this->accounts->first()?->id ?? '');
    }

    private function defaultCategoryId(): string
    {
        $categories = $this->expenseCategories;

        return (string) (($categories->firstWhere('name', 'Personal Care') ?? $categories->first())?->id ?? '');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'form_type', 'brand', 'name', 'category', 'price', 'purchased_on', 'opened_on', 'pao_months',
            'printed_expiry_on', 'quantity', 'status', 'ingredients_text', 'units_left', 'low_stock_threshold', 'notes', 'record_expense');
        $this->account_id = $this->defaultAccountId();
        $this->category_id = $this->defaultCategoryId();
    }
}; ?>

@php
    $today = now(auth()->user()->timezone)->toDateString();
    $currentType = ProductType::tryFrom($type);
    $expiryLabel = function (Product $product) use ($today): ?array {
        if ($product->expires_on === null) {
            return null;
        }

        $date = $product->expires_on->toDateString();
        $days = (int) \Carbon\CarbonImmutable::parse($today)->diffInDays(\Carbon\CarbonImmutable::parse($date), false);

        return match (true) {
            $days < 0 => [__('Expired :date', ['date' => $product->expires_on->format('M j')]), true],
            $days === 0 => [__('Expires today'), true],
            $days <= \App\Services\CareSummary::EXPIRING_SOON_DAYS => [trans_choice('Expires in :count day|Expires in :count days', $days), true],
            default => [__('Expires :date', ['date' => $product->expires_on->format('M j, Y')]), false],
        };
    };
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-care.tabs current="care.products" />

    {{-- Expiring soon --}}
    @if ($this->expiringSoon->isNotEmpty())
        <section class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30" data-test="expiring-soon">
            <flux:heading level="2" class="flex items-center gap-2">
                <flux:icon name="exclamation-triangle" variant="mini" class="text-amber-600 dark:text-amber-400" />
                {{ __('Expiring soon') }}
            </flux:heading>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($this->expiringSoon as $product)
                    @php [$label] = $expiryLabel($product); @endphp
                    <li wire:key="soon-{{ $product->id }}" class="flex flex-wrap justify-between gap-x-3">
                        <button type="button" wire:click="edit({{ $product->id }})" class="text-start font-medium">{{ $product->displayName() }}</button>
                        <span @class(['text-red-600 dark:text-red-400' => $product->expires_on->toDateString() < $today])>{{ $label }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="-mx-1 flex gap-1 overflow-x-auto px-1">
            <flux:button size="sm" :variant="$currentType === null ? 'filled' : 'ghost'" wire:click="$set('type', '')">{{ __('All') }}</flux:button>
            @foreach (ProductType::cases() as $option)
                <flux:button size="sm" :variant="$currentType === $option ? 'filled' : 'ghost'" wire:click="$set('type', '{{ $option->value }}')" data-test="filter-{{ $option->value }}">
                    {{ __($option->label()) }}
                </flux:button>
            @endforeach
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-product-button">{{ __('Add product') }}</flux:button>
    </div>

    <flux:switch wire:model.live="showAll" :label="__('Show finished & discarded')" align="left" />

    {{-- List --}}
    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->products as $product)
            @php $expiry = $expiryLabel($product); @endphp
            <li wire:key="product-{{ $product->id }}">
                <button type="button" wire:click="edit({{ $product->id }})" class="flex w-full items-start gap-3 px-4 py-3 text-start">
                    <div class="min-w-0 flex-1">
                        <span @class([
                            'block break-words text-sm font-medium',
                            'text-zinc-400 dark:text-zinc-500' => ! $product->status->isActive(),
                        ])>{{ $product->displayName() }}</span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                            <span>{{ __($product->type->label()) }}@if ($product->category) · {{ $product->category }}@endif</span>
                            @if ($product->quantity > 1)<span>× {{ $product->quantity }}</span>@endif
                            @if ($expiry && $product->status->isActive())
                                <span @class(['font-medium text-amber-700 dark:text-amber-400' => $expiry[1]])>{{ $expiry[0] }}</span>
                            @endif
                        </span>
                    </div>
                    <flux:badge size="sm" :color="match ($product->status) { ProductStatus::InUse => 'green', ProductStatus::Unopened => 'blue', default => 'zinc' }">
                        {{ __($product->status->label()) }}
                    </flux:badge>
                </button>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-state">
                {{ __('No products yet. Add your skincare, makeup and vitamins.') }}
            </li>
        @endforelse
    </ul>

    {{-- Add / edit window --}}
    <flux:modal name="product-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Add product') : __('Edit product') }}</flux:heading>

            <flux:radio.group wire:model.live="form_type" variant="segmented" :label="__('Type')">
                @foreach (ProductType::cases() as $option)
                    <flux:radio :value="$option->value" :label="__($option->label())" />
                @endforeach
            </flux:radio.group>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="brand" :label="__('Brand')" />
                <flux:input wire:model="name" :label="__('Name')" required />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="category" :label="__('Kind')" :placeholder="__('e.g. serum, lip tint')" />
                <flux:select wire:model="status" :label="__('Status')">
                    @foreach (ProductStatus::cases() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="price" :label="__('Price paid')" inputmode="decimal" placeholder="0.00" />
                <flux:input wire:model="quantity" type="number" min="0" :label="__('Quantity')" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="purchased_on" type="date" :label="__('Bought')" />
                <flux:input wire:model="opened_on" type="date" :label="__('Opened')" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="pao_months" type="number" min="1" max="120" :label="__('Use within (months)')" :description="__('After opening (PAO)')" />
                <flux:input wire:model="printed_expiry_on" type="date" :label="__('Printed expiry')" />
            </div>

            @if ($form_type === 'vitamin')
                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="units_left" type="number" min="0" :label="__('Tablets left')" />
                    <flux:input wire:model="low_stock_threshold" type="number" min="0" :label="__('Remind me at')" />
                </div>
            @endif

            <flux:textarea wire:model="ingredients_text" :label="__('Ingredients')" :placeholder="__('Paste the ingredient list')" rows="3" />
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

            {{-- Record as expense (D-045) --}}
            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:switch wire:model.live="record_expense" :label="__('Record as an expense in Finance')" align="left" data-test="record-expense" />
                @if ($record_expense)
                    @if ($this->accounts->isEmpty())
                        <flux:text class="text-sm">{{ __('Add an account in Finance first.') }}</flux:text>
                    @else
                        <div class="grid grid-cols-2 gap-3">
                            <flux:select wire:model="account_id" :label="__('Paid from')">
                                @foreach ($this->accounts as $option)
                                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model="category_id" :label="__('Category')">
                                @foreach ($this->expenseCategories as $option)
                                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif
                @endif
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingId !== null)
                    <flux:button variant="danger" wire:click="askDelete({{ $editingId }})" data-test="delete-product-button">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-product-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Delete window: asks about the money only when there's an expense (D-045) --}}
    <flux:modal name="delete-product" class="w-full max-w-md">
        @if ($this->deleting)
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->displayName()]) }}</flux:heading>

                @if ($this->deletingExpense)
                    @php $expense = $this->deletingExpense; @endphp
                    <flux:text data-test="money-question">
                        {{ __('It was recorded as a :amount expense from :account. Was this entry a mistake? Should the :amount go back to :account?', [
                            'amount' => money($expense->amount_cents),
                            'account' => $expense->account->name,
                        ]) }}
                    </flux:text>
                    <div class="flex flex-col gap-2">
                        <flux:button variant="danger" wire:click="delete(true)" data-test="delete-refund">
                            {{ __('Yes, put :amount back', ['amount' => money($expense->amount_cents)]) }}
                        </flux:button>
                        <flux:button wire:click="delete(false)" data-test="delete-keep">{{ __('No, keep the expense') }}</flux:button>
                        <flux:modal.close><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    </div>
                @else
                    <flux:text>{{ __('Used it up? You can mark it "Finished" instead to keep it in your history.') }}</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="danger" wire:click="delete(false)" data-test="delete-confirm">{{ __('Delete') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
