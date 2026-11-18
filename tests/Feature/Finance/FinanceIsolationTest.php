<?php

use App\Actions\Finance\CreateAccount;
use App\Actions\Finance\CreateContact;
use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteAccount;
use App\Actions\Finance\DeleteContact;
use App\Actions\Finance\DeleteDebt;
use App\Actions\Finance\DeleteDebtPayment;
use App\Actions\Finance\DeleteTransaction;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\RecordTransaction;
use App\Actions\Finance\RestoreContact;
use App\Actions\Finance\RestoreDebt;
use App\Actions\Finance\RestoreDebtPayment;
use App\Actions\Finance\RestoreTransaction;
use App\Actions\Finance\UpdateAccount;
use App\Actions\Finance\UpdateContact;
use App\Actions\Finance\UpdateDebt;
use App\Actions\Finance\UpdateTransaction;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
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

// Contacts, debts and payments (Prompt 4.2)

function lendFor(User $owner): Debt
{
    $contact = Contact::factory()->create();
    $account = Account::factory()->opening(100000)->create();

    return app(CreateDebt::class)->handle($owner, [
        'contact_id' => $contact->id, 'direction' => 'owed_to_me', 'principal' => '500',
        'account_id' => $account->id, 'started_on' => '2026-11-10', 'due_on' => '2026-11-30',
    ]);
}

test('a contact is invisible to another user', function () {
    assertInvisibleToOtherUser(
        fn (User $owner) => app(CreateContact::class)->handle($owner, ['name' => 'Ben'])
    );
});

test('a debt is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => lendFor($owner));
});

test('a debt payment is invisible to another user', function () {
    assertInvisibleToOtherUser(function (User $owner) {
        $debt = lendFor($owner);

        return app(RecordDebtPayment::class)->handle($owner, $debt, [
            'account_id' => $debt->account_id, 'amount' => '100', 'paid_on' => '2026-11-15',
        ]);
    });
});

test('another user gets a 404 from every contact, debt and payment action', function (Closure $call) {
    [$owner, $intruder] = User::factory()->count(2)->create();

    $this->actingAs($owner);
    $debt = lendFor($owner);
    $payment = app(RecordDebtPayment::class)->handle($owner, $debt, [
        'account_id' => $debt->account_id, 'amount' => '100', 'paid_on' => '2026-11-15',
    ]);
    $contact = Contact::findOrFail($debt->contact_id);
    $snapshot = [Transaction::count(), $debt->fresh()->getAttributes(), $contact->fresh()->getAttributes()];

    $this->actingAs($intruder);

    expect(fn () => $call($intruder, $contact, $debt, $payment))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });

    $this->actingAs($owner);
    expect([Transaction::count(), $debt->fresh()->getAttributes(), $contact->fresh()->getAttributes()])->toEqual($snapshot);
})->with([
    'update contact' => fn ($u, $c, $d, $p) => app(UpdateContact::class)->handle($u, $c, ['name' => 'Hacked']),
    'delete contact' => fn ($u, $c, $d, $p) => app(DeleteContact::class)->handle($u, $c, $c->name),
    'restore contact' => fn ($u, $c, $d, $p) => app(RestoreContact::class)->handle($u, $c),
    'update debt' => fn ($u, $c, $d, $p) => app(UpdateDebt::class)->handle($u, $d, []),
    'delete debt' => fn ($u, $c, $d, $p) => app(DeleteDebt::class)->handle($u, $d),
    'restore debt' => fn ($u, $c, $d, $p) => app(RestoreDebt::class)->handle($u, $d),
    'pay debt' => fn ($u, $c, $d, $p) => app(RecordDebtPayment::class)->handle($u, $d, []),
    'delete payment' => fn ($u, $c, $d, $p) => app(DeleteDebtPayment::class)->handle($u, $p),
    'restore payment' => fn ($u, $c, $d, $p) => app(RestoreDebtPayment::class)->handle($u, $p),
]);

test('a debt cannot use another user\'s contact or account, and a payment not their account', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirContact = Contact::factory()->create();
    $theirAccount = Account::factory()->create();

    $me = User::factory()->create();
    $this->actingAs($me);
    $myContact = Contact::factory()->create();
    $myDebt = lendFor($me);

    $input = ['direction' => 'i_owe', 'principal' => '100', 'started_on' => '2026-11-10'];

    expect(fn () => app(CreateDebt::class)->handle($me, [...$input, 'contact_id' => $theirContact->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreateDebt::class)->handle($me, [...$input, 'contact_id' => $myContact->id, 'account_id' => $theirAccount->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RecordDebtPayment::class)->handle($me, $myDebt, ['account_id' => $theirAccount->id, 'amount' => '100', 'paid_on' => '2026-11-15']))
        ->toThrow(ValidationException::class);

    expect(Debt::forUser($me)->count())->toBe(1)
        ->and(DebtPayment::forUser($me)->count())->toBe(0);
});
