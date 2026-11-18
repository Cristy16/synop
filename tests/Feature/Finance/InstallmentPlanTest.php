<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\UpdateDebt;
use App\Enums\Frequency;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Services\InstallmentPlan;
use App\Support\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->plans = app(InstallmentPlan::class);
    $this->wallet = Account::factory()->opening(10000000)->create();
    $this->ana = Contact::factory()->create(['name' => 'Ana']);

    // ₱3,000 at ₱500 monthly on the 15th, first due Dec 15 → 6 payments, last May 15.
    $this->borrow = fn (string $principal = '3,000', string $each = '500', array $overrides = []) => app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => $principal,
        'account_id' => $this->wallet->id, 'started_on' => '2026-11-10',
        'plan' => 'installments', 'installment' => $each, 'frequency' => 'monthly', 'day_of_month' => 15,
        'first_due_on' => '2026-12-15', ...$overrides,
    ]);
    $this->pay = fn (Debt $debt, string $amount, string $on = '2026-11-19') => app(RecordDebtPayment::class)->handle($this->user, $debt, [
        'account_id' => $this->wallet->id, 'amount' => $amount, 'paid_on' => $on,
    ]);
    $this->next = function (Debt $debt): array {
        $next = $this->plans->nextDue($debt->fresh());

        return $next === null ? [] : [$next->date, $next->amount->cents(), $next->number, $next->count];
    };
});

// Counting

test('the number of payments rounds up', function (int $principal, int $each, int $count) {
    expect($this->plans->paymentCount(Money::fromCents($principal), Money::fromCents($each)))->toBe($count);
})->with([
    '₱3,000 at ₱500' => [300000, 50000, 6],
    '₱3,000 at ₱700' => [300000, 70000, 5],
    '₱500 at ₱500' => [50000, 50000, 1],
    '₱1,000 at ₱300' => [100000, 30000, 4],
]);

test('the preview works out the last date', function () {
    $monthly15 = ['frequency' => Frequency::Monthly, 'interval' => null, 'weekdays' => null, 'day_of_month' => 15];

    expect($this->plans->preview(Money::fromCents(300000), Money::fromCents(50000), $monthly15, '2026-12-15'))
        ->toBe(['count' => 6, 'last' => '2027-05-15'])
        ->and($this->plans->preview(Money::fromCents(300000), Money::fromCents(70000), $monthly15, '2026-12-15'))
        ->toBe(['count' => 5, 'last' => '2027-04-15']);
});

test('a new plan gets its schedule from the first due date to the worked-out last date', function () {
    $debt = ($this->borrow)();

    $rule = ScheduleRule::where('schedulable_type', 'debt')->sole();

    expect($rule->schedulable_id)->toBe($debt->id)
        ->and($rule->frequency)->toBe(Frequency::Monthly)
        ->and($rule->starts_on->toDateString())->toBe('2026-12-15')
        ->and($rule->ends_on->toDateString())->toBe('2027-05-15')
        ->and($debt->fresh()->installment_cents->cents())->toBe(50000)
        ->and($debt->fresh()->due_on)->toBeNull();
});

// Next due date and amount

test('with nothing paid, the first payment is due', function () {
    expect(($this->next)(($this->borrow)()))->toBe(['2026-12-15', 50000, 1, 6]);
});

test('a partial payment leaves the same date due for the rest', function () {
    $debt = ($this->borrow)();
    ($this->pay)($debt, '300');

    expect(($this->next)($debt))->toBe(['2026-12-15', 20000, 1, 6]);
});

test('paying an installment moves the next due date forward', function () {
    $debt = ($this->borrow)();
    ($this->pay)($debt, '500');

    expect(($this->next)($debt))->toBe(['2027-01-15', 50000, 2, 6]);
});

test('an extra payment moves it further ahead', function () {
    $debt = ($this->borrow)();
    ($this->pay)($debt, '1,000');
    expect(($this->next)($debt))->toBe(['2027-02-15', 50000, 3, 6]);

    ($this->pay)($debt, '200');
    expect(($this->next)($debt))->toBe(['2027-02-15', 30000, 3, 6]);
});

test('a missed installment stays due (overdue), it doesn\'t skip ahead', function () {
    $debt = ($this->borrow)();
    $this->travelTo(CarbonImmutable::parse('2027-02-01 09:00:00', 'Asia/Manila'));

    expect(($this->next)($debt))->toBe(['2026-12-15', 50000, 1, 6]);
});

test('the last payment of an uneven plan is only what remains', function () {
    // ₱3,000 at ₱700: 700 × 4 = 2,800, so the 5th is ₱200.
    $debt = ($this->borrow)('3,000', '700');
    ($this->pay)($debt, '2,800');

    expect(($this->next)($debt))->toBe(['2027-04-15', 20000, 5, 5]);
});

test('if every planned date has passed, everything left is due on the last date', function () {
    $debt = ($this->borrow)();
    // Simulate a schedule that ended early (e.g. edited while overdue).
    ScheduleRule::where('schedulable_type', 'debt')->sole()->forceFill(['ends_on' => '2027-01-15'])->save();
    ($this->pay)($debt, '1,000');

    expect(($this->next)($debt))->toBe(['2027-01-15', 200000, 2, 6]);
});

test('settled and one-off debts have no next installment', function () {
    $debt = ($this->borrow)('500', '500');
    ($this->pay)($debt, '500');
    $oneOff = app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => '100', 'started_on' => '2026-11-10', 'due_on' => '2026-12-01',
    ]);

    expect($this->plans->nextDue($debt->fresh()))->toBeNull()
        ->and($this->plans->nextDue($oneOff->fresh()))->toBeNull();
});

// Versions (D-013)

test('payment dates are counted across schedule versions', function () {
    // Started in September: payments due Sep 15, Oct 15, Nov 15 are already behind us.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Manila'));
    $debt = ($this->borrow)('3,000', '500', ['started_on' => '2026-09-01', 'first_due_on' => '2026-09-15']);
    ($this->pay)($debt, '1,500', '2026-09-01');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    // Switch to weekly on Mondays: 3 payments left → Nov 23, Nov 30, Dec 7.
    app(UpdateDebt::class)->handle($this->user, $debt, [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => '3,000',
        'account_id' => $this->wallet->id, 'started_on' => '2026-09-01',
        'plan' => 'installments', 'installment' => '500', 'frequency' => 'weekly', 'weekdays' => [1],
        'first_due_on' => '2026-09-15',
    ]);

    [$old, $new] = ScheduleRule::where('schedulable_type', 'debt')->orderBy('starts_on')->get()->all();

    expect($old->ends_on->toDateString())->toBe('2026-11-18')
        ->and($new->starts_on->toDateString())->toBe('2026-11-19')
        ->and($new->ends_on->toDateString())->toBe('2026-12-07')
        ->and(($this->next)($debt))->toBe(['2026-11-23', 50000, 4, 6]);
});
