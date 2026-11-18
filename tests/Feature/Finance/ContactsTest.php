<?php

use App\Actions\Finance\CreateContact;
use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteContact;
use App\Actions\Finance\DeleteDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\RestoreContact;
use App\Actions\Finance\UpdateContact;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalances;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->wallet = Account::factory()->opening(1000000)->create(); // ₱10,000
    $this->ben = app(CreateContact::class)->handle($this->user, ['name' => 'Ben', 'note' => 'Classmate']);

    $this->lendBen = fn (string $principal = '500', ?string $due = '2026-11-30') => app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ben->id, 'direction' => 'owed_to_me', 'principal' => $principal,
        'account_id' => $this->wallet->id, 'started_on' => '2026-11-10', 'due_on' => $due,
    ]);
    $this->total = fn () => app(AccountBalances::class)->total($this->user)->cents();
});

test('a contact is created and updated', function () {
    expect($this->ben->name)->toBe('Ben')->and($this->ben->note)->toBe('Classmate');

    app(UpdateContact::class)->handle($this->user, $this->ben, ['name' => 'Benjie', 'note' => null]);

    expect($this->ben->fresh())->name->toBe('Benjie')->note->toBeNull();
});

test('a contact needs a name', function () {
    expect(fn () => app(CreateContact::class)->handle($this->user, ['name' => '']))->toThrow(ValidationException::class);
});

test('renaming a contact renames their debt tasks', function () {
    $debt = ($this->lendBen)();

    app(UpdateContact::class)->handle($this->user, $this->ben, ['name' => 'Benjie']);

    expect(Task::where('source_id', $debt->id)->sole()->title)->toBe('Collect ₱500.00 from Benjie');
});

test('a contact with no debts can be deleted and restored', function () {
    app(DeleteContact::class)->handle($this->user, $this->ben);
    expect(Contact::count())->toBe(0);

    app(RestoreContact::class)->handle($this->user, Contact::onlyTrashed()->findOrFail($this->ben->id));
    expect(Contact::count())->toBe(1);
});

test('a contact with only settled debts can be deleted, and the history stays', function () {
    $debt = ($this->lendBen)();
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '500', 'paid_on' => '2026-11-15']);

    app(DeleteContact::class)->handle($this->user, $this->ben);

    expect(Contact::count())->toBe(0)
        ->and(Debt::count())->toBe(1)
        ->and(Debt::sole()->status)->toBe(DebtStatus::Settled)
        ->and(Transaction::count())->toBe(2);
});

test('a contact with open debts can\'t be deleted without typing their name', function (?string $typed) {
    ($this->lendBen)();

    expect(fn () => app(DeleteContact::class)->handle($this->user, $this->ben, $typed))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors()['confirm_name'][0])
                ->toBe('Settle or delete Ben\'s open debts first, or type "Ben" to delete Ben and all their debts.');
        });

    expect(Contact::count())->toBe(1)->and(Debt::count())->toBe(1);
})->with([
    'nothing typed' => [null],
    'wrong name' => ['Ana'],
    'wrong case' => ['ben'],
]);

test('force-deleting hides the contact and everything linked, as if it never existed', function () {
    $before = ($this->total)();
    $open = ($this->lendBen)('500');
    $settled = ($this->lendBen)('300', null);
    app(RecordDebtPayment::class)->handle($this->user, $settled, ['account_id' => $this->wallet->id, 'amount' => '300', 'paid_on' => '2026-11-15']);
    app(RecordDebtPayment::class)->handle($this->user, $open, ['account_id' => $this->wallet->id, 'amount' => '100', 'paid_on' => '2026-11-15']);

    app(DeleteContact::class)->handle($this->user, $this->ben, ' Ben ');

    expect(Contact::count())->toBe(0)
        ->and(Debt::count())->toBe(0)
        ->and(DebtPayment::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(($this->total)())->toBe($before);
});

test('restoring a force-deleted contact brings back exactly what was hidden with them', function () {
    $open = ($this->lendBen)('500');
    $separately = ($this->lendBen)('200');
    app(RecordDebtPayment::class)->handle($this->user, $open, ['account_id' => $this->wallet->id, 'amount' => '100', 'paid_on' => '2026-11-15']);

    // Deleted on its own earlier: must stay deleted.
    app(DeleteDebt::class)->handle($this->user, $separately);
    $this->travel(1)->minutes();
    $totalBefore = ($this->total)();

    app(DeleteContact::class)->handle($this->user, $this->ben, 'Ben');
    $this->travel(1)->minutes();
    app(RestoreContact::class)->handle($this->user, Contact::onlyTrashed()->findOrFail($this->ben->id));

    expect(Contact::count())->toBe(1)
        ->and(Debt::pluck('id')->all())->toBe([$open->id])
        ->and(Debt::onlyTrashed()->pluck('id')->all())->toBe([$separately->id])
        ->and(DebtPayment::count())->toBe(1)
        ->and(Transaction::count())->toBe(2)
        ->and(($this->total)())->toBe($totalBefore)
        ->and(Task::where('source_id', $open->id)->sole()->title)->toBe('Collect ₱400.00 from Ben');
});
