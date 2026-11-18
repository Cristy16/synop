<?php

use App\Actions\Finance\CreateAccount;
use App\Actions\Finance\DeleteAccount;
use App\Actions\Finance\DeleteTransaction;
use App\Actions\Finance\RecordTransaction;
use App\Actions\Finance\RestoreTransaction;
use App\Actions\Finance\UpdateAccount;
use App\Actions\Finance\UpdateTransaction;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('an account is invisible to another user', function () {
    assertInvisibleToOtherUser(
        fn (User $owner) => app(CreateAccount::class)->handle($owner, ['name' => 'GCash', 'type' => 'ewallet'])
    );
});

test('a category is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => TransactionCategory::factory()->create());
});

test('a transaction is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => Transaction::factory()->create());
});

test('another user gets a 404 from every account and transaction action', function (Closure $call) {
    [$owner, $intruder] = User::factory()->count(2)->create();

    $this->actingAs($owner);
    $account = Account::factory()->create();
    $transaction = Transaction::factory()->create(['account_id' => $account->id]);
    $transaction->delete();
    $snapshot = [$account->fresh()->getAttributes(), Transaction::withTrashed()->findOrFail($transaction->id)->getAttributes()];
    $transaction->restore();

    $this->actingAs($intruder);

    expect(fn () => $call($intruder, $account, $transaction))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });

    $this->actingAs($owner);
    expect(Account::findOrFail($account->id)->getAttributes())->toEqual($snapshot[0]);
})->with([
    'update account' => fn (User $u, Account $a, Transaction $t) => app(UpdateAccount::class)->handle($u, $a, ['name' => 'Hacked', 'type' => 'cash']),
    'delete account' => fn (User $u, Account $a, Transaction $t) => app(DeleteAccount::class)->handle($u, $a),
    'update transaction' => fn (User $u, Account $a, Transaction $t) => app(UpdateTransaction::class)->handle($u, $t, []),
    'delete transaction' => fn (User $u, Account $a, Transaction $t) => app(DeleteTransaction::class)->handle($u, $t),
    'restore transaction' => fn (User $u, Account $a, Transaction $t) => app(RestoreTransaction::class)->handle($u, $t),
]);

test('a transaction cannot use another user\'s account or category', function (string $field) {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirAccount = Account::factory()->create();
    $theirCategory = TransactionCategory::factory()->create();

    $me = User::factory()->create();
    $this->actingAs($me);
    $myAccount = Account::factory()->create();
    $myOther = Account::factory()->create();
    $myCategory = TransactionCategory::factory()->create();

    $input = match ($field) {
        'account_id' => ['type' => 'expense', 'account_id' => $theirAccount->id, 'category_id' => $myCategory->id],
        'category_id' => ['type' => 'expense', 'account_id' => $myAccount->id, 'category_id' => $theirCategory->id],
        'to_account_id' => ['type' => 'transfer', 'account_id' => $myAccount->id, 'to_account_id' => $theirAccount->id],
    };

    expect(fn () => app(RecordTransaction::class)->handle($me, [...$input, 'amount' => '100', 'occurred_on' => '2026-11-18']))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Transaction::forUser($me)->count())->toBe(0)
        ->and(Transaction::forUser($other)->count())->toBe(0);
})->with(['account_id', 'category_id', 'to_account_id']);
