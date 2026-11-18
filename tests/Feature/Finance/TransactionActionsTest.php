<?php

use App\Actions\Finance\DeleteTransaction;
use App\Actions\Finance\RecordTransaction;
use App\Actions\Finance\RestoreTransaction;
use App\Actions\Finance\UpdateTransaction;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\AccountBalances;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->wallet = Account::factory()->opening(500000)->create(['name' => 'Wallet']);
    $this->gcash = Account::factory()->create(['name' => 'GCash']);
    $this->food = TransactionCategory::factory()->create(['name' => 'Food']);
    $this->allowance = TransactionCategory::factory()->income()->create(['name' => 'Allowance']);
});

function expense(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'account_id' => test()->wallet->id,
        'category_id' => test()->food->id,
        'amount' => '250.75',
        'occurred_on' => '2026-11-18',
        'note' => 'Lunch',
        ...$overrides,
    ];
}

function rejects(array $input, string $field): void
{
    expect(fn () => app(RecordTransaction::class)->handle(test()->user, $input))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Transaction::count())->toBe(0);
}

// Record

test('an expense is recorded', function () {
    $transaction = app(RecordTransaction::class)->handle($this->user, expense());

    $fresh = Transaction::findOrFail($transaction->id);

    expect($fresh->type)->toBe(TransactionType::Expense)
        ->and($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->account_id)->toBe($this->wallet->id)
        ->and($fresh->category_id)->toBe($this->food->id)
        ->and($fresh->getRawOriginal('amount_cents'))->toBe(25075)
        ->and($fresh->occurred_on->toDateString())->toBe('2026-11-18')
        ->and($fresh->note)->toBe('Lunch')
        ->and(app(AccountBalances::class)->of($this->wallet)->cents())->toBe(474925);
});

test('income is recorded with an income category', function () {
    app(RecordTransaction::class)->handle($this->user, [
        'type' => 'income', 'account_id' => $this->gcash->id, 'category_id' => $this->allowance->id,
        'amount' => '3,000', 'occurred_on' => '2026-11-18',
    ]);

    expect(app(AccountBalances::class)->of($this->gcash)->cents())->toBe(300000);
});

test('a transfer is recorded without a category', function () {
    app(RecordTransaction::class)->handle($this->user, [
        'type' => 'transfer', 'account_id' => $this->wallet->id, 'to_account_id' => $this->gcash->id,
        'amount' => '1,000', 'occurred_on' => '2026-11-18',
    ]);

    $balances = app(AccountBalances::class);
    expect($balances->of($this->wallet)->cents())->toBe(400000)
        ->and($balances->of($this->gcash)->cents())->toBe(100000)
        ->and($balances->total($this->user)->cents())->toBe(500000);
});

test('the amount must be more than zero', function (string $amount) {
    rejects(expense(['amount' => $amount]), 'amount');
})->with(['0', '0.00', '-50', 'abc', '']);

test('debt types cannot be recorded by hand', function (string $type) {
    rejects(expense(['type' => $type, 'category_id' => null]), 'type');
})->with(['debt_in', 'debt_out']);

test('an expense needs an expense category', function () {
    rejects(expense(['category_id' => null]), 'category_id');
    rejects(expense(['category_id' => $this->allowance->id]), 'category_id');
});

test('income needs an income category', function () {
    rejects(expense(['type' => 'income', 'category_id' => $this->food->id]), 'category_id');
});

test('a transfer needs a different destination account and no category', function () {
    $transfer = ['type' => 'transfer', 'account_id' => $this->wallet->id, 'amount' => '100', 'occurred_on' => '2026-11-18'];

    rejects($transfer, 'to_account_id');
    rejects([...$transfer, 'to_account_id' => $this->wallet->id], 'to_account_id');
    rejects([...$transfer, 'to_account_id' => $this->gcash->id, 'category_id' => $this->food->id], 'category_id');
});

test('income and expenses cannot have a destination account', function () {
    rejects(expense(['to_account_id' => $this->gcash->id]), 'to_account_id');
});

test('a deleted account cannot be used', function () {
    $this->gcash->delete(); // empty, so allowed

    rejects(expense(['account_id' => $this->gcash->id]), 'account_id');
    rejects([
        'type' => 'transfer', 'account_id' => $this->wallet->id, 'to_account_id' => $this->gcash->id,
        'amount' => '100', 'occurred_on' => '2026-11-18',
    ], 'to_account_id');
});

test('the date must be a real Y-m-d date', function (string $date) {
    rejects(expense(['occurred_on' => $date]), 'occurred_on');
})->with(['18/11/2026', '2026-02-30', '']);

test('a source cannot be set from the input', function () {
    $transaction = app(RecordTransaction::class)->handle($this->user, expense(['source_type' => 'debt', 'source_id' => 1, 'user_id' => 999]));

    expect($transaction->fresh()->source_type)->toBeNull()
        ->and($transaction->user_id)->toBe($this->user->id);
});

// Update / delete / restore

test('a transaction can be updated, including its type', function () {
    $transaction = app(RecordTransaction::class)->handle($this->user, expense());

    app(UpdateTransaction::class)->handle($this->user, $transaction, [
        'type' => 'transfer', 'account_id' => $this->wallet->id, 'to_account_id' => $this->gcash->id,
        'amount' => '99.99', 'occurred_on' => '2026-11-17',
    ]);

    $fresh = Transaction::findOrFail($transaction->id);
    expect($fresh->type)->toBe(TransactionType::Transfer)
        ->and($fresh->category_id)->toBeNull()
        ->and($fresh->note)->toBeNull()
        ->and($fresh->amount_cents->cents())->toBe(9999)
        ->and(app(AccountBalances::class)->total($this->user)->cents())->toBe(500000);
});

test('a transaction can be deleted and restored', function () {
    $transaction = app(RecordTransaction::class)->handle($this->user, expense());

    app(DeleteTransaction::class)->handle($this->user, $transaction);
    expect(Transaction::count())->toBe(0)
        ->and(app(AccountBalances::class)->of($this->wallet)->cents())->toBe(500000);

    app(RestoreTransaction::class)->handle($this->user, Transaction::onlyTrashed()->findOrFail($transaction->id));
    expect(Transaction::count())->toBe(1)
        ->and(app(AccountBalances::class)->of($this->wallet)->cents())->toBe(474925);
});

test('a transaction made by a debt or payment cannot be changed directly', function (string $action) {
    $transaction = Transaction::factory()->ofType(TransactionType::DebtOut)->create([
        'account_id' => $this->wallet->id, 'source_type' => 'debt_payment', 'source_id' => 1,
    ]);
    if ($action === 'restore') {
        $transaction->delete();
    }

    $call = match ($action) {
        'update' => fn () => app(UpdateTransaction::class)->handle($this->user, $transaction, expense()),
        'delete' => fn () => app(DeleteTransaction::class)->handle($this->user, $transaction),
        'restore' => fn () => app(RestoreTransaction::class)->handle($this->user, $transaction),
    };

    expect($call)->toThrow(function (AuthorizationException $e) {
        expect($e->getMessage())->toBe('This transaction was made by a debt payment. Change the debt payment instead.')
            ->and($e->status())->toBeNull();
    });
})->with(['update', 'delete', 'restore']);
