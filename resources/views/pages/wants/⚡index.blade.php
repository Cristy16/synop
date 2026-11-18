<?php

use App\Actions\Wants\ChangeWantStatus;
use App\Actions\Wants\CreateWant;
use App\Actions\Wants\DeleteWant;
use App\Actions\Wants\MarkWantBought;
use App\Actions\Wants\UpdateWant;
use App\Actions\Wants\WantBookkeeping;
use App\Enums\CategoryKind;
use App\Enums\FetchStatus;
use App\Enums\TaskPriority;
use App\Enums\WantProductType;
use App\Enums\WantStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\Want;
use App\Services\LinkPreview\DescriptionParser;
use App\Services\LinkPreview\LinkPreview;
use App\Services\LinkPreview\ShopName;
use App\Services\SafeToSpend;
use App\Support\SafeToSpendBreakdown;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Wants')] class extends Component {
    /** The open tab, kept in the address bar. */
    #[Url]
    public string $status = 'wanted';

    /** Safe-to-spend looks ahead to this date (empty = next payday, D-050). */
    #[Url]
    public string $until = '';

    // Add / edit window. The id is locked against tampering.
    #[Locked]
    public ?int $editingId = null;
    public string $url = '';
    public string $title = '';
    public string $price = '';
    public string $image_url = '';
    public string $shop = '';
    public string $description = '';
    public string $product_type = '';
    public string $expense_category_id = '';
    public string $ingredients_text = '';
    public string $priority = 'normal';
    public string $fetch_status = 'manual';
    /** What happened when the link was read: '' / 'fetched' / 'failed'. */
    public string $fetchNotice = '';

    // Mark bought window.
    #[Locked]
    public ?int $buyingId = null;
    public string $bought_on = '';
    public bool $record_expense = true;
    public string $account_id = '';
    public string $category_id = '';

    // Delete window.
    #[Locked]
    public ?int $deletingId = null;

    public function currentStatus(): WantStatus
    {
        return WantStatus::tryFrom($this->status) ?? WantStatus::Wanted;
    }

    #[Computed]
    public function safe(): SafeToSpendBreakdown
    {
        $until = $this->until !== '' && SafeToSpend::isValidDate($this->until) ? $this->until : null;

        return app(SafeToSpend::class)->for(Auth::user(), $until);
    }

    /**
     * @return Collection<int, Want>
     */
    #[Computed]
    public function wants(): Collection
    {
        return Want::where('status', $this->currentStatus()->value)
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->latest('id')
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return Want::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
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
    public function deleting(): ?Want
    {
        return $this->deletingId === null ? null : Want::find($this->deletingId);
    }

    #[Computed]
    public function deletingExpense(): ?Transaction
    {
        return $this->deleting === null ? null : app(WantBookkeeping::class)->expenseOf($this->deleting);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('want-form')->show();
    }

    public function edit(int $id): void
    {
        $want = Want::findOrFail($id);

        $this->resetForm();
        $this->editingId = $want->id;
        $this->url = $want->url ?? '';
        $this->title = $want->title;
        $this->price = $want->price_cents?->toDecimalString() ?? '';
        $this->image_url = $want->image_url ?? '';
        $this->shop = $want->shop ?? '';
        $this->description = $want->description ?? '';
        $this->product_type = $want->product_type->value ?? '';
        $this->expense_category_id = (string) ($want->expense_category_id ?? '');
        $this->ingredients_text = $want->ingredients_text ?? '';
        $this->priority = $want->priority->value;
        $this->fetch_status = $want->fetch_status->value;

        Flux::modal('want-form')->show();
    }

    /**
     * Pasting a link tries to read the page (D-049). Only empty fields are filled,
     * so nothing the user typed is overwritten. A failed read never stops them.
     */
    public function updatedUrl(LinkPreview $preview): void
    {
        $url = trim($this->url);
        $this->fetchNotice = '';

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fetch_status = FetchStatus::Manual->value;

            return;
        }

        $result = $preview->fetch($url);

        if ($this->shop === '') {
            $this->shop = $result->shop ?? ShopName::from($url) ?? '';
        }

        if (! $result->ok) {
            $this->fetch_status = FetchStatus::Failed->value;
            $this->fetchNotice = 'failed';

            return;
        }

        $this->fetch_status = FetchStatus::Fetched->value;
        $this->fetchNotice = 'fetched';
        $this->title = $this->title === '' ? ($result->title ?? '') : $this->title;
        $this->price = $this->price === '' ? ($result->price?->toDecimalString() ?? '') : $this->price;
        $this->image_url = $this->image_url === '' ? ($result->imageUrl ?? '') : $this->image_url;
    }

    /**
     * The "paste the product description" fallback: fills an empty title and price.
     */
    public function updatedDescription(): void
    {
        $parsed = DescriptionParser::parse($this->description);

        $this->title = $this->title === '' ? ($parsed['title'] ?? '') : $this->title;
        $this->price = $this->price === '' ? ($parsed['price']?->toDecimalString() ?? '') : $this->price;
    }

    public function save(CreateWant $create, UpdateWant $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();

        $input = [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'image_url' => $this->image_url,
            'shop' => $this->shop,
            'product_type' => $this->product_type === '' ? null : $this->product_type,
            'expense_category_id' => $this->expense_category_id === '' ? null : $this->expense_category_id,
            'ingredients_text' => $this->ingredients_text,
            'priority' => $this->priority,
            'fetch_status' => $this->fetch_status,
        ];

        $this->editingId === null
            ? $create->handle(Auth::user(), $input)
            : $update->handle(Auth::user(), Want::findOrFail($this->editingId), $input);

        Flux::modal('want-form')->close();
        Flux::toast(variant: 'success', text: $this->editingId === null ? __('Added to your wants.') : __('Saved.'));
        $this->resetForm();
    }

    public function askBought(int $id): void
    {
        $want = Want::findOrFail($id);

        $this->resetValidation();
        $this->buyingId = $want->id;
        $this->bought_on = now(Auth::user()->timezone)->toDateString();
        $this->record_expense = $want->price_cents !== null && ! $want->price_cents->isZero() && $this->accounts->isNotEmpty();
        $this->account_id = (string) ($this->accounts->first()?->id ?? '');
        $categories = $this->expenseCategories;
        $this->category_id = (string) (($want->expense_category_id !== null ? $categories->firstWhere('id', $want->expense_category_id) : null)
            ?? $categories->firstWhere('name', 'Shopping') ?? $categories->first())?->id;

        Flux::modal('want-form')->close();
        Flux::modal('buy-form')->show();
    }

    public function markBought(MarkWantBought $markBought): void
    {
        $this->resetValidation();

        $markBought->handle(Auth::user(), Want::findOrFail($this->buyingId), [
            'bought_on' => $this->bought_on,
            'record_expense' => $this->record_expense,
            'account_id' => $this->record_expense ? $this->account_id : null,
            'category_id' => $this->record_expense ? $this->category_id : null,
        ]);

        Flux::modal('buy-form')->close();
        Flux::toast(variant: 'success', text: $this->record_expense ? __('Marked bought, and recorded as an expense.') : __('Marked bought.'));
        $this->reset('buyingId', 'bought_on', 'record_expense', 'account_id', 'category_id');
    }

    public function setStatus(int $id, string $status, ChangeWantStatus $change): void
    {
        $change->handle(Auth::user(), Want::findOrFail($id), WantStatus::from($status));

        Flux::modal('want-form')->close();
        Flux::toast(text: $status === 'dropped' ? __('Dropped.') : __('Back on your list.'));
        $this->resetForm();
    }

    public function askDelete(int $id): void
    {
        $this->deletingId = Want::findOrFail($id)->id;

        Flux::modal('want-form')->close();
        Flux::modal('delete-want')->show();
    }

    /**
     * @param  bool  $putMoneyBack  the answer to "was this a mistake?" (only asked when there's an expense)
     */
    public function delete(DeleteWant $delete, bool $putMoneyBack = false): void
    {
        $delete->handle(Auth::user(), Want::findOrFail($this->deletingId), $putMoneyBack);

        Flux::modal('delete-want')->close();
        Flux::toast(text: $putMoneyBack ? __('Deleted. The money is back in the account.') : __('Deleted.'));
        $this->reset('deletingId');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'url', 'title', 'price', 'image_url', 'shop', 'description', 'product_type',
            'expense_category_id', 'ingredients_text', 'priority', 'fetch_status', 'fetchNotice');
    }
}; ?>

@php
    $current = $this->currentStatus();
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl" level="1">{{ __('Wants') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-want-button">{{ __('Add a want') }}</flux:button>
    </div>

    {{-- Safe to spend (computed in PHP, never by the AI — D-027) --}}
    @php $safe = $this->safe; @endphp
    <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="safe-to-spend">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:text class="text-sm">{{ __('Safe to spend until :date', ['date' => \Carbon\CarbonImmutable::parse($safe->until)->format('D, M j')]) }}</flux:text>
                <div @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-red-600 dark:text-red-400' => $safe->safe->isNegative()]) data-test="safe-amount">
                    {{ money($safe->safe) }}
                </div>
            </div>
            <div class="w-40">
                <flux:input wire:model.live="until" type="date" :label="__('Until')" size="sm" :value="$safe->until" />
            </div>
        </div>
        <details class="mt-3 text-sm">
            <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">{{ __('How it\'s worked out') }}</summary>
            <dl class="mt-2 space-y-1 tabular-nums">
                <div class="flex justify-between"><dt>{{ __('All your accounts') }}</dt><dd>{{ money($safe->balance) }}</dd></div>
                @foreach ($safe->owed as $line)
                    <div class="flex justify-between text-zinc-600 dark:text-zinc-300">
                        <dt>− {{ __('Owe :name, due :date', ['name' => $line['debt']->contact?->name, 'date' => \Carbon\CarbonImmutable::parse($line['due_on'])->format('M j')]) }}</dt>
                        <dd>{{ money($line['amount']) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between text-zinc-600 dark:text-zinc-300"><dt>− {{ __('Safety buffer') }}</dt><dd>{{ money($safe->buffer) }}</dd></div>
                <div class="flex justify-between border-t border-zinc-200 pt-1 font-medium dark:border-zinc-700"><dt>{{ __('Safe to spend') }}</dt><dd>{{ money($safe->safe) }}</dd></div>
            </dl>
            <flux:text class="mt-2 text-xs">
                {{ __('Paydays and the safety buffer are in Settings → Preferences.') }}
            </flux:text>
        </details>
    </section>

    <nav class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1" aria-label="{{ __('Filter wants') }}">
        @foreach (WantStatus::cases() as $tab)
            <flux:button size="sm" :variant="$tab === $current ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $tab->value }}')" class="shrink-0" data-test="tab-{{ $tab->value }}">
                {{ __($tab->label()) }}
                @if (($this->counts[$tab->value] ?? 0) > 0)<span class="ms-1 tabular-nums">{{ $this->counts[$tab->value] }}</span>@endif
            </flux:button>
        @endforeach
    </nav>

    <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->wants as $want)
            <li wire:key="want-{{ $want->id }}" class="flex items-start gap-3 px-4 py-3">
                @if ($want->image_url)
                    <img src="{{ $want->image_url }}" alt="" referrerpolicy="no-referrer" loading="lazy" class="size-14 shrink-0 rounded-lg bg-zinc-100 object-cover dark:bg-zinc-800">
                @else
                    <div class="flex size-14 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800"><flux:icon name="gift" class="text-zinc-400" /></div>
                @endif
                <button type="button" wire:click="edit({{ $want->id }})" class="min-w-0 flex-1 text-start">
                    <span class="block break-words text-sm font-medium">{{ $want->title }}</span>
                    <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($want->price_cents)<span class="font-medium text-zinc-700 dark:text-zinc-200">{{ money($want->price_cents) }}</span>@endif
                        @if ($want->price_cents && $want->status === WantStatus::Wanted)
                            @if ($want->price_cents->cents() <= $safe->safe->cents())
                                <flux:badge size="sm" color="green" data-test="fits">{{ __('Fits') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber" data-test="over">{{ __(':amount over', ['amount' => money(\App\Support\Money::fromCents($want->price_cents->cents() - max(0, $safe->safe->cents())))]) }}</flux:badge>
                            @endif
                        @endif
                        @if ($want->shop)<span>{{ $want->shop }}</span>@endif
                        @if ($want->bought_on)<span>{{ __('bought :date', ['date' => $want->bought_on->format('M j')]) }}</span>@endif
                        @if ($want->priority === TaskPriority::High)<flux:badge size="sm" color="red">{{ __('High') }}</flux:badge>@endif
                    </span>
                </button>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    @if ($want->url)
                        <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$want->url" target="_blank" rel="noopener noreferrer" :aria-label="__('Open in shop')" />
                    @endif
                    @if ($want->status === WantStatus::Wanted)
                        <flux:button size="xs" wire:click="askBought({{ $want->id }})" data-test="buy-{{ $want->id }}">{{ __('Bought') }}</flux:button>
                    @endif
                </div>
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-state">
                {{ match ($current) {
                    WantStatus::Wanted => __('Nothing on your list. Paste a link from Shopee, Lazada or any shop.'),
                    WantStatus::Bought => __('Nothing bought yet.'),
                    WantStatus::Dropped => __('Nothing dropped.'),
                } }}
            </li>
        @endforelse
    </ul>

    {{-- Add / edit window --}}
    <flux:modal name="want-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId === null ? __('Add a want') : __('Edit want') }}</flux:heading>

            <div>
                <flux:input wire:model.live.blur="url" type="url" :label="__('Link')" :placeholder="__('Paste a product link (optional)')" data-test="want-url" />
                <div wire:loading wire:target="url" class="mt-1 text-xs text-zinc-500">{{ __('Reading the page…') }}</div>
                @if ($fetchNotice === 'failed')
                    <flux:text class="mt-1 text-xs text-amber-700 dark:text-amber-400" data-test="fetch-failed">
                        {{ __('Couldn\'t read this page (shops often block it). Fill in the details, or paste the product description below.') }}
                    </flux:text>
                @elseif ($fetchNotice === 'fetched')
                    <flux:text class="mt-1 text-xs text-green-700 dark:text-green-400" data-test="fetch-ok">{{ __('Details filled in from the page. Check them.') }}</flux:text>
                @endif
            </div>

            <flux:input wire:model="title" :label="__('What is it?')" required />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="price" :label="__('Price')" inputmode="decimal" placeholder="0.00" />
                <flux:input wire:model="shop" :label="__('Shop')" />
            </div>

            <flux:textarea wire:model.live.blur="description" :label="__('Product description')" :description="__('Paste it from the shop. An empty name and price are filled in from it.')" rows="3" data-test="want-description" />

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model.live="product_type" :label="__('Type')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    @foreach (WantProductType::cases() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="priority" :label="__('Priority')">
                    @foreach (TaskPriority::cases() as $option)
                        <flux:select.option :value="$option->value">{{ __($option->label()) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model="expense_category_id" :label="__('Spending category')" :description="__('Used to compare with this month\'s spending.')">
                <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                @foreach ($this->expenseCategories as $option)
                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if (in_array($product_type, ['skincare', 'makeup'], true))
                <flux:textarea wire:model="ingredients_text" :label="__('Ingredients')" :placeholder="__('Paste the ingredient list')" rows="3" />
            @endif

            <flux:input wire:model="image_url" :label="__('Image link')" :placeholder="__('https://…')" />

            <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                @if ($editingId !== null)
                    <div class="flex flex-wrap gap-2">
                        <flux:button variant="danger" wire:click="askDelete({{ $editingId }})" data-test="delete-want-button">{{ __('Delete') }}</flux:button>
                        @php $editingStatus = Want::find($editingId)?->status; @endphp
                        @if ($editingStatus === WantStatus::Wanted)
                            <flux:button wire:click="setStatus({{ $editingId }}, 'dropped')" data-test="drop-button">{{ __('Drop') }}</flux:button>
                        @elseif ($editingStatus === WantStatus::Bought)
                            <flux:button wire:click="setStatus({{ $editingId }}, 'wanted')" wire:confirm="{{ __('Move it back to your list? Its recorded expense is removed and the money goes back to the account.') }}" data-test="unbuy-button">{{ __('Not bought') }}</flux:button>
                        @else
                            <flux:button wire:click="setStatus({{ $editingId }}, 'wanted')" data-test="restore-button">{{ __('Want it again') }}</flux:button>
                        @endif
                    </div>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-want-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Mark bought window --}}
    <flux:modal name="buy-form" class="w-full max-w-md">
        <form wire:submit="markBought" class="space-y-5">
            <flux:heading size="lg">{{ __('Mark bought') }}</flux:heading>
            <flux:input wire:model="bought_on" type="date" :label="__('Bought on')" required />
            <flux:switch wire:model.live="record_expense" :label="__('Record as an expense in Finance')" align="left" />
            <flux:error name="record_expense" />
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
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="confirm-bought-button">{{ __('Mark bought') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete window: asks about the money only when there's an expense (D-045, D-049) --}}
    <flux:modal name="delete-want" class="w-full max-w-md">
        @if ($this->deleting)
            <div class="space-y-5">
                <flux:heading size="lg">{{ __('Delete :name?', ['name' => $this->deleting->title]) }}</flux:heading>
                @if ($this->deletingExpense)
                    @php $expense = $this->deletingExpense; @endphp
                    <flux:text data-test="money-question">
                        {{ __('It was recorded as a :amount expense from :account. Was this entry a mistake? Should the :amount go back to :account?', [
                            'amount' => money($expense->amount_cents), 'account' => $expense->account->name,
                        ]) }}
                    </flux:text>
                    <div class="flex flex-col gap-2">
                        <flux:button variant="danger" wire:click="delete(true)" data-test="delete-refund">{{ __('Yes, put :amount back', ['amount' => money($expense->amount_cents)]) }}</flux:button>
                        <flux:button wire:click="delete(false)" data-test="delete-keep">{{ __('No, keep the expense') }}</flux:button>
                        <flux:modal.close><flux:button variant="ghost" class="w-full">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    </div>
                @else
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="danger" wire:click="delete(false)" data-test="delete-confirm">{{ __('Delete') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
