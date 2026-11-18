<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\DeleteDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Finance\UpdateDebt;
use App\Actions\Schedules\RemoveSchedules;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use App\Services\DebtSummary;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila, payments_due_days = 7
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->wallet = Account::factory()->opening(10000000)->create(['name' => 'Wallet']);
    $this->ana = Contact::factory()->create(['name' => 'Ana']);

    $this->input = fn (array $overrides = []) => [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => '3,000',
        'account_id' => $this->wallet->id, 'started_on' => '2026-11-10',
        'plan' => 'installments', 'installment' => '500', 'frequency' => 'monthly', 'day_of_month' => 15,
        'first_due_on' => '2026-12-15', ...$overrides,
    ];
    $this->borrow = fn (array $overrides = []) => app(CreateDebt::class)->handle($this->user, ($this->input)($overrides));
    $this->pay = fn (Debt $debt, string $amount) => app(RecordDebtPayment::class)->handle($this->user, $debt, [
        'account_id' => $this->wallet->id, 'amount' => $amount, 'paid_on' => '2026-11-19',
    ]);
    $this->task = fn (Debt $debt) => Task::where('source_type', 'debt')->where('source_id', $debt->id)->sole();
});

// Creating

test('an installment debt\'s task is due on the first payment date, for one installment', function () {
    $debt = ($this->borrow)();

    expect(($this->task)($debt))
        ->title->toBe('Pay Ana ₱500.00')
        ->due_on->toDateString()->toBe('2026-12-15');
});

test('invalid installment plans are rejected', function (array $overrides, string $field) {
    expect(fn () => ($this->borrow)($overrides))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Debt::count())->toBe(0)->and(ScheduleRule::count())->toBe(0);
})->with([
    'no amount per payment' => [['installment' => ''], 'installment'],
    'zero per payment' => [['installment' => '0'], 'installment'],
    'more than the total' => [['installment' => '3,000.01'], 'installment'],
    'too many payments' => [['installment' => '10'], 'installment'],
    'also a due date' => [['due_on' => '2026-12-01'], 'due_on'],
    'first due before the debt started' => [['first_due_on' => '2026-11-09'], 'first_due_on'],
    'no first due date' => [['first_due_on' => ''], 'first_due_on'],
    'weekly without days' => [['frequency' => 'weekly', 'day_of_month' => null, 'weekdays' => []], 'weekdays'],
    'monthly day 32' => [['day_of_month' => 32], 'day_of_month'],
]);

// Paying

test('each payment moves the task to the next due date and amount; the last one completes it', function () {
    $debt = ($this->borrow)(['principal' => '1,000']); // 2 payments: Dec 15, Jan 15

    ($this->pay)($debt, '300');
    expect(($this->task)($debt))->title->toBe('Pay Ana ₱200.00')->due_on->toDateString()->toBe('2026-12-15');

    ($this->pay)($debt, '200');
    expect(($this->task)($debt))->title->toBe('Pay Ana ₱500.00')->due_on->toDateString()->toBe('2027-01-15');

    ($this->pay)($debt, '500');
    expect($debt->fresh()->status)->toBe(DebtStatus::Settled)
        ->and(($this->task)($debt))
        ->completed_at->not->toBeNull()
        ->due_on->toDateString()->toBe('2027-01-15');
});

test('paying more than what remains is still refused', function () {
    $debt = ($this->borrow)();

    expect(fn () => ($this->pay)($debt, '3,000.01'))->toThrow(ValidationException::class);
});

// Changing the plan

test('a plan that hasn\'t started is edited in place', function () {
    $debt = ($this->borrow)();

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->input)(['installment' => '1,000', 'first_due_on' => '2026-12-20', 'day_of_month' => 20]));

    $rule = ScheduleRule::where('schedulable_type', 'debt')->sole();
    expect($rule->starts_on->toDateString())->toBe('2026-12-20')
        ->and($rule->ends_on->toDateString())->toBe('2027-02-20')
        ->and(($this->task)($debt)->title)->toBe('Pay Ana ₱1,000.00');
});

test('a running plan with the same pattern only moves its end date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Manila'));
    $debt = ($this->borrow)(['started_on' => '2026-11-01', 'first_due_on' => '2026-11-15']); // Nov 15 … Apr 15
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->input)(['principal' => '4,000', 'started_on' => '2026-11-01', 'first_due_on' => '2026-11-15']));

    $rule = ScheduleRule::where('schedulable_type', 'debt')->sole();
    expect($rule->starts_on->toDateString())->toBe('2026-11-15')
        ->and($rule->ends_on->toDateString())->toBe('2027-06-15'); // 8 payments
});

test('switching to one payment removes the schedule; the task follows the due date', function () {
    $debt = ($this->borrow)();

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->input)(['plan' => 'one_off', 'due_on' => '2026-12-31']));

    expect(ScheduleRule::count())->toBe(0)
        ->and($debt->fresh()->installment_cents)->toBeNull()
        ->and(($this->task)($debt))->title->toBe('Pay Ana ₱3,000.00')->due_on->toDateString()->toBe('2026-12-31');
});

test('switching a one-off debt to installments creates the schedule', function () {
    $debt = app(CreateDebt::class)->handle($this->user, ($this->input)(['plan' => 'one_off', 'due_on' => '2026-12-31']));
    expect(ScheduleRule::count())->toBe(0);

    app(UpdateDebt::class)->handle($this->user, $debt, ($this->input)());

    expect(ScheduleRule::count())->toBe(1)
        ->and($debt->fresh()->due_on)->toBeNull()
        ->and(($this->task)($debt)->due_on->toDateString())->toBe('2026-12-15');
});

test('deleting an installment debt removes its task; the schedule stays for a restore', function () {
    $debt = ($this->borrow)();

    app(DeleteDebt::class)->handle($this->user, $debt);

    expect(Task::count())->toBe(0)->and(ScheduleRule::count())->toBe(1);
});

// Payments Due, Record payment, Debts page

test('Payments Due lists an installment debt by its next date and amount due', function () {
    ($this->borrow)(['first_due_on' => '2026-11-20', 'day_of_month' => 20]);

    [$item] = app(DebtSummary::class)->dueSoon($this->user);

    expect($item->date)->toBe('2026-11-20')
        ->and($item->amount->cents())->toBe(50000)
        ->and($item->installment?->number)->toBe(1)
        ->and($item->installment?->count)->toBe(6);

    Livewire::test('today.payments-due')
        ->assertSee('Pay Ana ₱500.00')
        ->assertSee('payment 1 of 6');
});

test('an installment due later than the window isn\'t listed yet', function () {
    ($this->borrow)(); // Dec 15 is more than 7 days away

    expect(app(DebtSummary::class)->dueSoon($this->user))->toBe([]);
});

test('Record payment is pre-filled with the amount due now, not everything left', function () {
    $debt = ($this->borrow)();

    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $debt->id)
        ->assertSet('amount', '500.00');
});

test('the Debts form previews the plan and saves it', function () {
    Livewire::test('pages::finance.debts')
        ->call('createDebt', $this->ana->id)
        ->set('direction', 'i_owe')
        ->set('principal', '3,000')
        ->set('account_id', (string) $this->wallet->id)
        ->set('plan', 'installments')
        ->set('installment', '500')
        ->set('plan_frequency', 'monthly')
        ->set('plan_day', '15')
        ->set('first_due_on', '2026-12-15')
        ->assertSee('6 payments')
        ->assertSee('last on May 15, 2027')
        ->call('saveDebt')
        ->assertHasNoErrors()
        ->assertSee('↻ ₱500.00 · Monthly on the 15th')
        ->assertSee('next Dec 15 (₱500.00)');

    expect(ScheduleRule::sole()->ends_on->toDateString())->toBe('2027-05-15');
});

test('plan errors land on the plan fields', function () {
    Livewire::test('pages::finance.debts')
        ->call('createDebt', $this->ana->id)
        ->set('principal', '3,000')
        ->set('plan', 'installments')
        ->set('installment', '500')
        ->set('plan_frequency', 'weekly')
        ->set('plan_weekdays', [])
        ->set('first_due_on', '2026-12-15')
        ->call('saveDebt')
        ->assertHasErrors(['plan_weekdays']);
});

test('editing an installment debt loads its plan', function () {
    $debt = ($this->borrow)();

    Livewire::test('pages::finance.debts')
        ->call('editDebt', $debt->id)
        ->assertSet('plan', 'installments')
        ->assertSet('installment', '500.00')
        ->assertSet('plan_day', '15')
        ->assertSet('first_due_on', '2026-12-15');
});

// Cross-user

test('another user\'s debt schedules can\'t be removed', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirContact = Contact::factory()->create();
    $theirs = app(CreateDebt::class)->handle($other, [
        'contact_id' => $theirContact->id, 'direction' => 'i_owe', 'principal' => '1,000', 'started_on' => '2026-11-10',
        'plan' => 'installments', 'installment' => '500', 'frequency' => 'monthly', 'day_of_month' => 15, 'first_due_on' => '2026-12-15',
    ]);
    $this->actingAs($this->user);

    expect(fn () => app(RemoveSchedules::class)->handle($this->user, $theirs))->toThrow(LogicException::class);

    expect(ScheduleRule::forUser($other)->count())->toBe(1);
});
