<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalances;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->balances = app(AccountBalances::class);
});

test('a new account\'s balance is its opening balance', function () {
    $account = Account::factory()->opening(150050)->create();

    expect($this->balances->of($account)->cents())->toBe(150050);
});

test('each type moves the balance the right way', function (TransactionType $type, int $expected) {
    $account = Account::factory()->opening(100000)->create();

    $factory = Transaction::factory()->state(['account_id' => $account->id, 'amount_cents' => 25000]);
    $factory = match ($type) {
        TransactionType::Income => $factory->income(),
        TransactionType::Expense => $factory,
        default => $factory->ofType($type),
    };
    $factory->create();

    expect($this->balances->of($account)->cents())->toBe($expected);
})->with([
    'income adds' => [TransactionType::Income, 125000],
    'expense subtracts' => [TransactionType::Expense, 75000],
    'debt_in adds' => [TransactionType::DebtIn, 125000],
    'debt_out subtracts' => [TransactionType::DebtOut, 75000],
]);

test('a transfer moves money between accounts without changing the total', function () {
    $wallet = Account::factory()->opening(500000)->create(['name' => 'Wallet']);
    $gcash = Account::factory()->opening(100000)->create(['name' => 'GCash']);
    $totalBefore = $this->balances->total($this->user)->cents();

    Transaction::factory()->transferTo($gcash)->create(['account_id' => $wallet->id, 'amount_cents' => 120050]);

    expect($this->balances->of($wallet)->cents())->toBe(379950)
        ->and($this->balances->of($gcash)->cents())->toBe(220050)
        ->and($this->balances->total($this->user)->cents())->toBe($totalBefore)
        ->and($totalBefore)->toBe(600000);
});

test('balances for all accounts and the total add up', function () {
    $wallet = Account::factory()->opening(100000)->create();
    $bank = Account::factory()->opening(2000000)->create();
    $empty = Account::factory()->create();

    Transaction::factory()->income()->create(['account_id' => $bank->id, 'amount_cents' => 1500000]);
    Transaction::factory()->create(['account_id' => $wallet->id, 'amount_cents' => 35075]);
    Transaction::factory()->transferTo($wallet)->create(['account_id' => $bank->id, 'amount_cents' => 300000]);

    expect(array_map(fn ($m) => $m->cents(), $this->balances->forUser($this->user)))->toBe([
        $wallet->id => 364925,
        $bank->id => 3200000,
        $empty->id => 0,
    ])->and($this->balances->total($this->user)->cents())->toBe(3564925);
});

test('a deleted transaction stops counting, and counts again when restored', function () {
    $account = Account::factory()->opening(100000)->create();
    $expense = Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 40000]);

    $expense->delete();
    expect($this->balances->of($account)->cents())->toBe(100000);

    $expense->restore();
    expect($this->balances->of($account)->cents())->toBe(60000);
});

test('deleted accounts are left out of the total', function () {
    Account::factory()->opening(100000)->create();
    Account::factory()->create()->delete(); // empty, so deleting is allowed

    expect($this->balances->forUser($this->user))->toHaveCount(1)
        ->and($this->balances->total($this->user)->cents())->toBe(100000);
});

test('a user with no accounts has a zero total', function () {
    expect($this->balances->forUser($this->user))->toBe([])
        ->and($this->balances->total($this->user)->isZero())->toBeTrue();
});

test('another user\'s transactions never change my balances', function () {
    $mine = Account::factory()->opening(100000)->create();

    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Account::factory()->opening(999900)->create();
    Transaction::factory()->income()->create(['account_id' => $theirs->id, 'amount_cents' => 50000]);

    $this->actingAs($this->user);

    expect($this->balances->of($mine)->cents())->toBe(100000)
        ->and($this->balances->total($this->user)->cents())->toBe(100000);
});

test('large amounts are summed exactly, with no rounding', function () {
    $account = Account::factory()->opening(0)->create();

    // ₱90 billion and 1 centavo, ten times: far beyond float precision for centavos if floats were used.
    Transaction::factory()->count(10)->income()->create(['account_id' => $account->id, 'amount_cents' => 9_000_000_000_001]);

    expect($this->balances->of($account)->cents())->toBe(90_000_000_000_010);
});
