<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
});

// All tabs

test('every Finance tab loads, is linked from the sidebar, and guests are sent to login', function (string $route) {
    $this->get(route($route))->assertOk()->assertSee(route('finance.overview'));

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['finance.overview', 'finance.accounts', 'finance.transactions', 'finance.debts']);

test('the tab bar highlights the current tab', function () {
    $html = $this->get(route('finance.accounts'))->getContent();
    $tagOf = fn (string $route) => preg_match('/<a[^>]*data-test="tab-'.preg_quote($route, '/').'"[^>]*>/s', $html, $m) ? $m[0] : '';

    expect($tagOf('finance.accounts'))->toContain('aria-current="page"')
        ->and($tagOf('finance.overview'))->not->toContain('aria-current');
});

// Overview

test('the overview shows balance, owed to me, I owe and net', function () {
    $wallet = Account::factory()->opening(1000000)->create(['name' => 'Wallet']);
    $ben = Contact::factory()->create(['name' => 'Ben']);
    $ana = Contact::factory()->create(['name' => 'Ana']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '1,200', 'account_id' => $wallet->id, 'started_on' => '2026-11-01']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $ana->id, 'direction' => 'i_owe', 'principal' => '500', 'started_on' => '2026-11-01', 'due_on' => '2026-11-20']);

    Livewire::test('pages::finance.overview')
        ->assertSeeInOrder(['Total balance', '₱8,800.00', 'Owed to me', '₱1,200.00', 'I owe', '₱500.00', 'Net', '₱9,500.00'])
        ->assertSee('Wallet')
        ->assertSee('Pay Ana ₱500.00');
});

// Accounts

test('an account can be added from the Accounts tab', function () {
    Livewire::test('pages::finance.accounts')
        ->call('create')
        ->set('name', 'GCash')
        ->set('type', 'ewallet')
        ->set('opening_balance', '1,500.50')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('GCash')
        ->assertSee('₱1,500.50');

    expect(Account::sole()->opening_balance_cents->cents())->toBe(150050);
});

test('account errors are shown', function () {
    Livewire::test('pages::finance.accounts')
        ->call('create')
        ->set('name', '')
        ->set('opening_balance', '-5')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Account::count())->toBe(0);
});

test('an account can be edited, and an empty one deleted', function () {
    $account = Account::factory()->create(['name' => 'Old']);

    Livewire::test('pages::finance.accounts')
        ->call('edit', $account->id)
        ->assertSet('name', 'Old')
        ->set('name', 'BDO')
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit', $account->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect(Account::count())->toBe(0)->and(Account::withTrashed()->sole()->name)->toBe('BDO');
});

test('an account with money in it shows why it can\'t be deleted', function () {
    $account = Account::factory()->opening(120000)->create();

    Livewire::test('pages::finance.accounts')
        ->call('edit', $account->id)
        ->call('delete')
        ->assertHasErrors(['delete'])
        ->assertSee('Move the ₱1,200.00 to another account first.');

    expect(Account::count())->toBe(1);
});

// Transactions

test('the transactions tab starts on this month and keeps filters in the address', function () {
    Livewire::test('pages::finance.transactions')
        ->assertSet('month', '2026-11');

    Livewire::withQueryParams(['month' => '2026-10', 'type' => 'income'])
        ->test('pages::finance.transactions')
        ->assertSet('month', '2026-10')
        ->assertSet('type', 'income');
});

test('an expense can be recorded, edited and deleted', function () {
    $wallet = Account::factory()->opening(100000)->create(['name' => 'Wallet']);
    $food = TransactionCategory::factory()->create(['name' => 'Food']);

    $page = Livewire::test('pages::finance.transactions')
        ->call('create')
        ->assertSet('account_id', (string) $wallet->id)
        ->assertSet('occurred_on', '2026-11-19')
        ->set('form_type', 'expense')
        ->set('category_id', (string) $food->id)
        ->set('amount', '250.75')
        ->set('note', 'Lunch')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Lunch')
        ->assertSee('−₱250.75');

    $transaction = Transaction::sole();
    expect($transaction->amount_cents->cents())->toBe(25075);

    $page->call('edit', $transaction->id)
        ->assertSet('amount', '250.75')
        ->set('amount', '300')
        ->call('save')
        ->assertHasNoErrors();
    expect($transaction->fresh()->amount_cents->cents())->toBe(30000);

    $page->call('edit', $transaction->id)->call('delete');
    expect(Transaction::count())->toBe(0);
});

test('a transfer is recorded with a destination account', function () {
    $wallet = Account::factory()->create(['name' => 'Wallet']);
    $gcash = Account::factory()->create(['name' => 'GCash']);

    Livewire::test('pages::finance.transactions')
        ->call('create')
        ->set('form_type', 'transfer')
        ->set('account_id', (string) $wallet->id)
        ->set('to_account_id', (string) $gcash->id)
        ->set('amount', '1,000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Wallet → GCash');

    expect(Transaction::sole()->type)->toBe(TransactionType::Transfer);
});

test('transaction errors land on the form, not the filters', function () {
    Account::factory()->create();

    Livewire::test('pages::finance.transactions')
        ->call('create')
        ->set('form_type', 'expense')
        ->set('amount', '0')
        ->call('save')
        ->assertHasErrors(['category_id'])
        ->assertHasNoErrors(['type']);
});

test('a debt\'s transaction opens no edit window', function () {
    $wallet = Account::factory()->create();
    $ben = Contact::factory()->create();
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '100', 'account_id' => $wallet->id, 'started_on' => '2026-11-10']);

    Livewire::test('pages::finance.transactions')
        ->assertSee('From debt')
        ->call('edit', Transaction::sole()->id)
        ->assertSet('editingId', null);
});

// Debts

test('the debts tab groups debts by contact with each net', function () {
    $ben = Contact::factory()->create(['name' => 'Ben']);
    Contact::factory()->create(['name' => 'Ana']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '700', 'started_on' => '2026-11-10', 'due_on' => '2026-11-15']);

    Livewire::test('pages::finance.debts')
        ->assertSeeInOrder(['Ana', 'All square', 'Ben', 'Ben owes you ₱700.00'])
        ->assertSee('₱700.00 of ₱700.00')
        ->assertSee('Overdue · was due Nov 15');
});

test('a contact and a debt can be added from the debts tab', function () {
    $wallet = Account::factory()->create();

    $page = Livewire::test('pages::finance.debts')
        ->call('createContact')
        ->set('contact_name', 'Ben')
        ->call('saveContact')
        ->assertHasNoErrors();

    $ben = Contact::sole();

    $page->call('createDebt', $ben->id)
        ->assertSet('contact_id', (string) $ben->id)
        ->assertSet('started_on', '2026-11-19')
        ->set('direction', 'i_owe')
        ->set('principal', '500')
        ->set('account_id', (string) $wallet->id)
        ->set('due_on', '2026-11-30')
        ->call('saveDebt')
        ->assertHasNoErrors()
        ->assertSee('You owe Ben ₱500.00');

    expect(Debt::sole()->principal_cents->cents())->toBe(50000);
});

test('contact errors use the page\'s field names', function () {
    Livewire::test('pages::finance.debts')
        ->call('createContact')
        ->set('contact_name', '')
        ->call('saveContact')
        ->assertHasErrors(['contact_name']);
});

test('a debt can be edited and deleted', function () {
    $ben = Contact::factory()->create(['name' => 'Ben']);
    $debt = app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '700', 'started_on' => '2026-11-10']);

    $page = Livewire::test('pages::finance.debts')
        ->call('editDebt', $debt->id)
        ->assertSet('principal', '700.00')
        ->set('principal', '800')
        ->call('saveDebt')
        ->assertHasNoErrors();
    expect($debt->fresh()->principal_cents->cents())->toBe(80000);

    $page->call('editDebt', $debt->id)->call('deleteDebt');
    expect(Debt::count())->toBe(0);
});

test('payments can be listed and one deleted', function () {
    $wallet = Account::factory()->create(['name' => 'Wallet']);
    $ben = Contact::factory()->create(['name' => 'Ben']);
    $debt = app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '700', 'started_on' => '2026-11-10']);
    $payment = app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $wallet->id, 'amount' => '200', 'paid_on' => '2026-11-12']);

    Livewire::test('pages::finance.debts')
        ->call('togglePayments', $debt->id)
        ->assertSee('Nov 12, 2026 · Wallet')
        ->call('deletePayment', $payment->id)
        ->assertSee('₱700.00 of ₱700.00');
});

test('settled debts appear only with "Show settled"', function () {
    $wallet = Account::factory()->create();
    $ben = Contact::factory()->create(['name' => 'Ben']);
    $debt = app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '100', 'started_on' => '2026-11-10', 'description' => 'Snacks']);
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $wallet->id, 'amount' => '100', 'paid_on' => '2026-11-12']);

    Livewire::test('pages::finance.debts')
        ->assertDontSee('Snacks')
        ->set('showSettled', true)
        ->assertSee('Snacks')
        ->assertSee('Settled');
});

test('deleting a contact with open debts asks for their name first', function () {
    $ben = Contact::factory()->create(['name' => 'Ben']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $ben->id, 'direction' => 'owed_to_me', 'principal' => '100', 'started_on' => '2026-11-10']);

    $page = Livewire::test('pages::finance.debts')
        ->call('askDeleteContact', $ben->id)
        ->assertSee('Type Ben to confirm')
        ->call('deleteContact')
        ->assertHasErrors(['confirm_name']);
    expect(Contact::count())->toBe(1);

    $page->set('confirm_name', 'Ben')->call('deleteContact')->assertHasNoErrors();
    expect(Contact::count())->toBe(0)->and(Debt::count())->toBe(0);
});

test('a contact without open debts is deleted without typing the name', function () {
    $ben = Contact::factory()->create(['name' => 'Ben']);

    Livewire::test('pages::finance.debts')
        ->call('askDeleteContact', $ben->id)
        ->assertDontSee('Type Ben to confirm')
        ->call('deleteContact')
        ->assertHasNoErrors();

    expect(Contact::count())->toBe(0);
});

// Cross-user

test('another user\'s ids return 404 from every Finance page action', function (string $page, string $method) {
    $other = User::factory()->create();
    $this->actingAs($other);
    $account = Account::factory()->opening(100000)->create();
    $contact = Contact::factory()->create();
    $transaction = Transaction::factory()->create(['account_id' => $account->id]);
    $debt = app(CreateDebt::class)->handle($other, ['contact_id' => $contact->id, 'direction' => 'owed_to_me', 'principal' => '100', 'account_id' => $account->id, 'started_on' => '2026-11-10']);
    $payment = app(RecordDebtPayment::class)->handle($other, $debt, ['account_id' => $account->id, 'amount' => '50', 'paid_on' => '2026-11-12']);
    $this->actingAs($this->user);

    $id = match ($method) {
        'edit' => $page === 'pages::finance.accounts' ? $account->id : $transaction->id,
        'editContact', 'askDeleteContact', 'createDebt' => $contact->id,
        'editDebt', 'togglePayments' => $debt->id,
        'deletePayment' => $payment->id,
    };

    Livewire::test($page)->call($method, $id)->assertNotFound();

    $this->actingAs($other);
    expect($debt->fresh()->status)->toBe(DebtStatus::Open)
        ->and(DebtPayment::count())->toBe(1);
})->with([
    ['pages::finance.accounts', 'edit'],
    ['pages::finance.transactions', 'edit'],
    ['pages::finance.debts', 'editContact'],
    ['pages::finance.debts', 'askDeleteContact'],
    ['pages::finance.debts', 'createDebt'],
    ['pages::finance.debts', 'editDebt'],
    ['pages::finance.debts', 'togglePayments'],
    ['pages::finance.debts', 'deletePayment'],
]);

test('another user\'s records never show on any tab or in any dropdown', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Account::factory()->create(['name' => 'Their Secret Bank']);
    Contact::factory()->create(['name' => 'Their Secret Friend']);
    TransactionCategory::factory()->create(['name' => 'Their Secret Category']);
    $this->actingAs($this->user);

    foreach (['finance.overview', 'finance.accounts', 'finance.transactions', 'finance.debts'] as $route) {
        $this->get(route($route))
            ->assertDontSee('Their Secret Bank')
            ->assertDontSee('Their Secret Friend')
            ->assertDontSee('Their Secret Category');
    }
});

test('locked ids can\'t be changed from the browser', function (string $page, string $property) {
    Livewire::test($page)->set($property, 1);
})->with([
    ['pages::finance.accounts', 'editingId'],
    ['pages::finance.transactions', 'editingId'],
    ['pages::finance.debts', 'editingContactId'],
    ['pages::finance.debts', 'editingDebtId'],
    ['pages::finance.debts', 'deletingContactId'],
    ['finance.record-payment', 'debtId'],
])->throws(CannotUpdateLockedPropertyException::class);
