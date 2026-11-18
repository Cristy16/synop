<?php

use App\Actions\Finance\CreateAccount;
use App\Actions\Finance\DeleteAccount;
use App\Actions\Finance\UpdateAccount;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('an account is created with its opening balance parsed from text', function () {
    $account = app(CreateAccount::class)->handle($this->user, [
        'name' => 'GCash',
        'type' => 'ewallet',
        'opening_balance' => '₱1,500.50',
    ]);

    $fresh = Account::findOrFail($account->id);

    expect($fresh->name)->toBe('GCash')
        ->and($fresh->type)->toBe(AccountType::Ewallet)
        ->and($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->getRawOriginal('opening_balance_cents'))->toBe(150050);
});

test('a blank opening balance means zero', function () {
    $account = app(CreateAccount::class)->handle($this->user, ['name' => 'Wallet', 'type' => 'cash', 'opening_balance' => null]);

    expect($account->opening_balance_cents->isZero())->toBeTrue();
});

test('invalid account input is rejected', function (array $input, string $field) {
    expect(fn () => app(CreateAccount::class)->handle($this->user, $input))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Account::count())->toBe(0);
})->with([
    'missing name' => [['type' => 'cash'], 'name'],
    'name too long' => [['name' => str_repeat('a', 101), 'type' => 'cash'], 'name'],
    'unknown type' => [['name' => 'X', 'type' => 'crypto'], 'type'],
    'not an amount' => [['name' => 'X', 'type' => 'cash', 'opening_balance' => 'abc'], 'opening_balance'],
    'negative opening balance' => [['name' => 'X', 'type' => 'cash', 'opening_balance' => '-100'], 'opening_balance'],
]);

test('an account can be updated', function () {
    $account = Account::factory()->create(['name' => 'Old']);

    app(UpdateAccount::class)->handle($this->user, $account, ['name' => 'BPI Savings', 'type' => 'bank', 'opening_balance' => '2,000']);

    $fresh = Account::findOrFail($account->id);
    expect($fresh->name)->toBe('BPI Savings')
        ->and($fresh->type)->toBe(AccountType::Bank)
        ->and($fresh->opening_balance_cents->cents())->toBe(200000);
});

test('an empty account can be deleted, softly', function () {
    $account = Account::factory()->opening(50000)->create();
    // Spend it all: balance is now zero.
    Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 50000]);

    app(DeleteAccount::class)->handle($this->user, $account);

    expect(Account::count())->toBe(0)
        ->and(Account::withTrashed()->count())->toBe(1)
        ->and(Transaction::count())->toBe(1, 'its history stays');
});

test('an account with money in it cannot be deleted', function () {
    $account = Account::factory()->opening(120000)->create();

    expect(fn () => app(DeleteAccount::class)->handle($this->user, $account))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->getMessage())->toBe('Move the ₱1,200.00 to another account first.');
        });

    expect(Account::count())->toBe(1);
});

test('an overdrawn account cannot be deleted either', function () {
    $account = Account::factory()->create();
    Transaction::factory()->create(['account_id' => $account->id, 'amount_cents' => 5000]);

    expect(fn () => app(DeleteAccount::class)->handle($this->user, $account))
        ->toThrow(AuthorizationException::class);
});

test('accounts are deleted with their user', function () {
    Account::factory()->count(2)->create();

    $this->user->delete();

    expect(Account::forUser($this->user)->withTrashed()->count())->toBe(0);
});
