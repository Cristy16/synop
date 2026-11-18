<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\RestoreDebt;
use App\Actions\Finance\UpdateDebt;
use App\Actions\Tasks\DeleteTask;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalances;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->wallet = Account::factory()->opening(1000000)->create(['name' => 'Wallet']); // ₱10,000
    $this->ben = Contact::factory()->create(['name' => 'Ben']);
    $this->ana = Contact::factory()->create(['name' => 'Ana']);

    $this->debtInput = fn (array $overrides = []) => [
        'contact_id' => $this->ben->id,
        'direction' => 'owed_to_me',
        'principal' => '500',
        'description' => 'Concert ticket',
        'account_id' => $this->wallet->id,
        'started_on' => '2026-11-10',
        'due_on' => '2026-11-30',
        ...$overrides,
    ];
    $this->balance = fn () => app(AccountBalances::class)->of($this->wallet)->cents();
    $this->taskFor = fn (Debt $debt) => Task::where('source_type', 'debt')->where('source_id', $debt->id)->first();
});

// Create, both directions

test('lending money: owed to me takes the principal out of the account', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    $principal = Transaction::where('source_type', 'debt')->sole();

    expect($debt->direction)->toBe(DebtDirection::OwedToMe)
        ->and($debt->status)->toBe(DebtStatus::Open)
        ->and($debt->remaining()->cents())->toBe(50000)
        ->and($principal->type)->toBe(TransactionType::DebtOut)
        ->and($principal->source_id)->toBe($debt->id)
        ->and($principal->occurred_on->toDateString())->toBe('2026-11-10')
        ->and(($this->balance)())->toBe(950000);
});

test('borrowing money: I owe brings the principal into the account', function () {
    app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['direction' => 'i_owe', 'contact_id' => $this->ana->id]));

    expect(Transaction::where('source_type', 'debt')->sole()->type)->toBe(TransactionType::DebtIn)
        ->and(($this->balance)())->toBe(1050000);
});

test('a debt without an account moves no money', function () {
    app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['account_id' => null]));

    expect(Transaction::count())->toBe(0)
        ->and(($this->balance)())->toBe(1000000);
});

test('debts never count as income or spending', function () {
    app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    expect(Transaction::whereIn('type', ['income', 'expense'])->count())->toBe(0);
});

test('invalid debt input is rejected', function (array $overrides, string $field) {
    expect(fn () => app(CreateDebt::class)->handle($this->user, ($this->debtInput)($overrides)))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Debt::count())->toBe(0)->and(Transaction::count())->toBe(0);
})->with([
    'zero principal' => [['principal' => '0'], 'principal'],
    'negative principal' => [['principal' => '-500'], 'principal'],
    'not an amount' => [['principal' => 'five hundred'], 'principal'],
    'unknown direction' => [['direction' => 'both'], 'direction'],
    'missing contact' => [['contact_id' => null], 'contact_id'],
    'due before start' => [['due_on' => '2026-11-01'], 'due_on'],
    'bad start date' => [['started_on' => '10/11/2026'], 'started_on'],
]);

test('a deleted contact or account cannot be used', function () {
    $this->ana->delete();
    $empty = Account::factory()->create();
    $empty->delete();

    expect(fn () => app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['contact_id' => $this->ana->id])))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['account_id' => $empty->id])))
        ->toThrow(ValidationException::class);
});

// The linked task

test('a debt with a due date creates a linked task, both directions', function () {
    $lent = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    $borrowed = app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['direction' => 'i_owe', 'contact_id' => $this->ana->id, 'principal' => '1,250.50']));

    expect(($this->taskFor)($lent))
        ->title->toBe('Collect ₱500.00 from Ben')
        ->due_on->toDateString()->toBe('2026-11-30')
        ->completed_at->toBeNull()
        ->and(($this->taskFor)($borrowed)->title)->toBe('Pay Ana ₱1,250.50');
});

test('a debt without a due date has no task, until one is added', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['due_on' => null]));
    expect(Task::count())->toBe(0);

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['due_on' => '2026-12-05']));
    expect(($this->taskFor)($debt)->due_on->toDateString())->toBe('2026-12-05');

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['due_on' => null]));
    expect(Task::count())->toBe(0);
});

test('a debt\'s task cannot be deleted from the Tasks page', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    expect(fn () => app(DeleteTask::class)->handle($this->user, ($this->taskFor)($debt)))
        ->toThrow(AuthorizationException::class);

    expect(Task::count())->toBe(1);
});

// Update

test('editing a debt moves its principal transaction and renames its task', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    $gcash = Account::factory()->create(['name' => 'GCash']);

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)([
        'principal' => '800', 'account_id' => $gcash->id, 'contact_id' => $this->ana->id,
    ]));

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::sole()->account_id)->toBe($gcash->id)
        ->and(Transaction::sole()->amount_cents->cents())->toBe(80000)
        ->and(($this->balance)())->toBe(1000000)
        ->and(app(AccountBalances::class)->of($gcash)->cents())->toBe(-80000)
        ->and(($this->taskFor)($debt)->title)->toBe('Collect ₱800.00 from Ana');
});

test('removing the account removes the principal transaction', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['account_id' => null]));

    expect(Transaction::count())->toBe(0)->and(($this->balance)())->toBe(1000000);
});

test('with payments, the principal can\'t drop below what was paid', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '300', 'paid_on' => '2026-11-15']);

    expect(fn () => app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['principal' => '299.99'])))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors()['principal'][0])->toBe('₱300.00 has already been paid, so the amount can\'t be less than that.');
        });
});

test('lowering the principal to exactly what was paid settles the debt', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '300', 'paid_on' => '2026-11-15']);

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['principal' => '300']));

    expect($debt->fresh()->status)->toBe(DebtStatus::Settled)
        ->and(($this->taskFor)($debt)->completed_at)->not->toBeNull();
});

test('with payments, the direction can\'t change', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '100', 'paid_on' => '2026-11-15']);

    expect(fn () => app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['direction' => 'i_owe'])))
        ->toThrow(ValidationException::class);

    expect($debt->fresh()->direction)->toBe(DebtDirection::OwedToMe);
});

test('without payments, the direction can change and the principal transaction flips', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->debtInput)(['direction' => 'i_owe']));

    expect(Transaction::sole()->type)->toBe(TransactionType::DebtIn)
        ->and(($this->balance)())->toBe(1050000)
        ->and(($this->taskFor)($debt)->title)->toBe('Pay Ben ₱500.00');
});

// Delete / restore

test('deleting a debt hides it with its payments and transactions, and removes its task', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '200', 'paid_on' => '2026-11-15']);

    app(DeleteDebt::class)->handle($this->user, $debt);

    expect(Debt::count())->toBe(0)
        ->and(Transaction::count())->toBe(0)
        ->and(Task::count())->toBe(0)
        ->and(($this->balance)())->toBe(1000000, 'balance is back to before the debt');
});

test('restoring a debt brings back what was hidden with it, and its task', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)());
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '200', 'paid_on' => '2026-11-15']);
    $this->travel(5)->minutes();
    app(DeleteDebt::class)->handle($this->user, $debt);
    $this->travel(5)->minutes();

    app(RestoreDebt::class)->handle($this->user, Debt::onlyTrashed()->findOrFail($debt->id));

    $fresh = Debt::findOrFail($debt->id);
    expect($fresh->remaining()->cents())->toBe(30000)
        ->and(Transaction::count())->toBe(2)
        ->and(($this->balance)())->toBe(970000)
        ->and(($this->taskFor)($fresh)->title)->toBe('Collect ₱300.00 from Ben');
});

test('a debt can\'t be restored while its contact is deleted', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->debtInput)(['contact_id' => $this->ana->id]));
    app(DeleteDebt::class)->handle($this->user, $debt);
    $this->ana->delete();

    expect(fn () => app(RestoreDebt::class)->handle($this->user, Debt::onlyTrashed()->findOrFail($debt->id)))
        ->toThrow(ValidationException::class);
});

test('debts are deleted with their user', function () {
    app(CreateDebt::class)->handle($this->user, ($this->debtInput)());

    $this->user->delete();

    expect(Debt::forUser($this->user)->withTrashed()->count())->toBe(0);
});
