<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteDebtPayment;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\RestoreDebtPayment;
use App\Enums\DebtStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountBalances;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->wallet = Account::factory()->opening(1000000)->create(['name' => 'Wallet']); // ₱10,000
    $this->gcash = Account::factory()->create(['name' => 'GCash']);
    $this->ben = Contact::factory()->create(['name' => 'Ben']);

    $this->makeDebt = fn (string $direction = 'owed_to_me', string $principal = '1,000') => app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ben->id,
        'direction' => $direction,
        'principal' => $principal,
        'account_id' => $this->wallet->id,
        'started_on' => '2026-11-10',
        'due_on' => '2026-11-30',
    ]);
    $this->pay = fn (Debt $debt, string $amount, array $overrides = []) => app(RecordDebtPayment::class)->handle($this->user, $debt, [
        'account_id' => $this->gcash->id,
        'amount' => $amount,
        'paid_on' => '2026-11-18',
        ...$overrides,
    ]);
    $this->balanceOf = fn (Account $account) => app(AccountBalances::class)->of($account)->cents();
    $this->taskFor = fn (Debt $debt) => Task::where('source_type', 'debt')->where('source_id', $debt->id)->sole();
});

// Partial payments and settling

test('partial payments lower what remains, then the last one settles the debt', function () {
    $debt = ($this->makeDebt)();

    ($this->pay)($debt, '300');

    $debt->refresh();
    expect($debt->remaining()->cents())->toBe(70000)
        ->and($debt->status)->toBe(DebtStatus::Open)
        ->and(($this->taskFor)($debt))
        ->title->toBe('Collect ₱700.00 from Ben')
        ->completed_at->toBeNull();

    ($this->pay)($debt, '700');

    $debt->refresh();
    expect($debt->remaining()->isZero())->toBeTrue()
        ->and($debt->status)->toBe(DebtStatus::Settled)
        ->and($debt->settled_at)->not->toBeNull()
        ->and(($this->taskFor)($debt)->completed_at)->not->toBeNull()
        ->and(($this->taskFor)($debt)->title)->toBe('Collect ₱700.00 from Ben', 'a settled task keeps its last title');
});

test('each payment creates exactly one transaction, linked to it', function () {
    $debt = ($this->makeDebt)();
    $payment = ($this->pay)($debt, '250.50', ['note' => 'GCash transfer']);

    $transaction = Transaction::where('source_type', 'debt_payment')->sole();

    expect($transaction->source_id)->toBe($payment->id)
        ->and($transaction->account_id)->toBe($this->gcash->id)
        ->and($transaction->amount_cents->cents())->toBe(25050)
        ->and($transaction->occurred_on->toDateString())->toBe('2026-11-18')
        ->and($transaction->note)->toBe('GCash transfer');
});

// Both directions

test('being repaid (owed to me) brings money in', function () {
    $debt = ($this->makeDebt)('owed_to_me');

    ($this->pay)($debt, '400');

    expect(Transaction::where('source_type', 'debt_payment')->sole()->type)->toBe(TransactionType::DebtIn)
        ->and(($this->balanceOf)($this->wallet))->toBe(900000)  // lent ₱1,000 from the wallet
        ->and(($this->balanceOf)($this->gcash))->toBe(40000);   // ₱400 repaid into GCash
});

test('repaying (I owe) takes money out', function () {
    $debt = ($this->makeDebt)('i_owe');

    ($this->pay)($debt, '400');

    expect(Transaction::where('source_type', 'debt_payment')->sole()->type)->toBe(TransactionType::DebtOut)
        ->and(($this->balanceOf)($this->wallet))->toBe(1100000) // borrowed ₱1,000 into the wallet
        ->and(($this->balanceOf)($this->gcash))->toBe(-40000)   // ₱400 repaid from GCash
        ->and(($this->taskFor)($debt)->title)->toBe('Pay Ben ₱600.00');
});

test('a fully repaid debt leaves the total where it started', function (string $direction) {
    $before = app(AccountBalances::class)->total($this->user)->cents();
    $debt = ($this->makeDebt)($direction);

    ($this->pay)($debt, '1,000', ['account_id' => $this->wallet->id]);

    expect(app(AccountBalances::class)->total($this->user)->cents())->toBe($before);
})->with(['owed_to_me', 'i_owe']);

// Overpaying

test('paying more than what remains is refused', function () {
    $debt = ($this->makeDebt)();
    ($this->pay)($debt, '600');

    expect(fn () => ($this->pay)($debt, '400.01'))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors()['amount'][0])->toBe('Only ₱400.00 is left to pay.');
        });

    expect(DebtPayment::count())->toBe(1)
        ->and($debt->fresh()->remaining()->cents())->toBe(40000);
});

test('a second payment for the full amount is refused', function () {
    $debt = ($this->makeDebt)();

    ($this->pay)($debt, '1,000');

    expect(fn () => ($this->pay)($debt, '1,000'))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors()['amount'][0])->toBe('This debt is already settled.');
        });

    expect(DebtPayment::count())->toBe(1);
});

test('invalid payments are rejected', function (array $overrides, string $field) {
    $debt = ($this->makeDebt)();

    expect(fn () => ($this->pay)($debt, $overrides['amount'] ?? '100', $overrides))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(DebtPayment::count())->toBe(0);
})->with([
    'zero' => [['amount' => '0'], 'amount'],
    'negative' => [['amount' => '-100'], 'amount'],
    'no account' => [['account_id' => null], 'account_id'],
    'before the debt started' => [['paid_on' => '2026-11-09'], 'paid_on'],
    'bad date' => [['paid_on' => 'yesterday'], 'paid_on'],
]);

// Deleting and restoring a payment

test('deleting a payment hides its transaction and reopens a settled debt', function () {
    $debt = ($this->makeDebt)();
    ($this->pay)($debt, '400');
    $last = ($this->pay)($debt, '600');
    expect($debt->fresh()->status)->toBe(DebtStatus::Settled);

    app(DeleteDebtPayment::class)->handle($this->user, $last);

    $debt->refresh();
    expect($debt->status)->toBe(DebtStatus::Open)
        ->and($debt->settled_at)->toBeNull()
        ->and($debt->remaining()->cents())->toBe(60000)
        ->and(Transaction::where('source_type', 'debt_payment')->count())->toBe(1)
        ->and(($this->balanceOf)($this->gcash))->toBe(40000)
        ->and(($this->taskFor)($debt))
        ->completed_at->toBeNull()
        ->title->toBe('Collect ₱600.00 from Ben');
});

test('a deleted payment can be restored', function () {
    $debt = ($this->makeDebt)();
    $payment = ($this->pay)($debt, '1,000');
    app(DeleteDebtPayment::class)->handle($this->user, $payment);

    app(RestoreDebtPayment::class)->handle($this->user, DebtPayment::onlyTrashed()->findOrFail($payment->id));

    expect($debt->fresh()->status)->toBe(DebtStatus::Settled)
        ->and(($this->balanceOf)($this->gcash))->toBe(100000);
});

test('restoring a payment that would now overpay is refused', function () {
    $debt = ($this->makeDebt)();
    $first = ($this->pay)($debt, '600');
    app(DeleteDebtPayment::class)->handle($this->user, $first);
    ($this->pay)($debt, '1,000');

    expect(fn () => app(RestoreDebtPayment::class)->handle($this->user, DebtPayment::onlyTrashed()->findOrFail($first->id)))
        ->toThrow(ValidationException::class);

    expect(DebtPayment::count())->toBe(1);
});

test('a payment\'s transaction cannot be edited directly', function () {
    $debt = ($this->makeDebt)();
    ($this->pay)($debt, '100');

    $transaction = Transaction::where('source_type', 'debt_payment')->sole();

    expect(Gate::allows('delete', $transaction))->toBeFalse()
        ->and(Gate::allows('update', $transaction))->toBeFalse();
});
