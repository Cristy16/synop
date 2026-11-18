<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Actions\Settings\UpdatePreferences;
use App\Actions\Wants\CreateWant;
use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Services\SafeToSpend;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->wallet = Account::factory()->opening(1000000)->create(); // ₱10,000
    $this->ana = Contact::factory()->create(['name' => 'Ana']);
    $this->ben = Contact::factory()->create(['name' => 'Ben']);
    $this->sts = app(SafeToSpend::class);
    $this->debt = fn (array $o) => app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => '1,000', 'started_on' => '2026-11-01', ...$o,
    ]);
    $this->setPaydays = fn (?array $days) => $this->user->forceFill(['paydays' => $days])->save();
});

// The formula

test('safe = balance − what I owe before the date − the buffer', function () {
    $this->user->forceFill(['safety_buffer_cents' => 150000])->save(); // ₱1,500
    ($this->debt)(['principal' => '1,000', 'due_on' => '2026-11-25']); // counts
    ($this->debt)(['principal' => '2,000', 'due_on' => '2026-12-10']); // after the date
    ($this->debt)(['principal' => '500', 'due_on' => '2026-11-10']);   // overdue: counts
    ($this->debt)(['principal' => '700']);                             // no due date: doesn't count
    ($this->debt)(['principal' => '900', 'direction' => 'owed_to_me', 'contact_id' => $this->ben->id, 'due_on' => '2026-11-20']); // owed to me: not subtracted

    $result = $this->sts->for($this->user, '2026-11-30');

    expect($result->balance->cents())->toBe(1000000)
        ->and($result->owedTotal->cents())->toBe(150000)
        ->and($result->buffer->cents())->toBe(150000)
        ->and($result->safe->cents())->toBe(700000)
        ->and(array_map(fn ($l) => $l['due_on'], $result->owed))->toBe(['2026-11-10', '2026-11-25']);
});

test('debts due on the date itself don\'t count ("due before")', function () {
    ($this->debt)(['due_on' => '2026-11-30']);

    expect($this->sts->for($this->user, '2026-11-30')->owedTotal->isZero())->toBeTrue()
        ->and($this->sts->for($this->user, '2026-12-01')->owedTotal->cents())->toBe(100000);
});

test('only what remains is counted', function () {
    $debt = ($this->debt)(['due_on' => '2026-11-25']);
    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '600', 'paid_on' => '2026-11-15']);

    expect($this->sts->for($this->user, '2026-11-30')->owedTotal->cents())->toBe(40000);
});

test('installments count the payments falling before the date, capped at what remains', function () {
    // ₱3,000 at ₱500 monthly on the 15th from Nov 15 → Nov 15, Dec 15, Jan 15…
    $debt = ($this->debt)(['principal' => '3,000', 'plan' => 'installments', 'installment' => '500', 'frequency' => 'monthly', 'day_of_month' => 15, 'first_due_on' => '2026-11-15']);

    expect($this->sts->for($this->user, '2026-11-30')->owedTotal->cents())->toBe(50000)   // Nov 15 (overdue)
        ->and($this->sts->for($this->user, '2027-01-16')->owedTotal->cents())->toBe(150000); // Nov, Dec, Jan

    app(RecordDebtPayment::class)->handle($this->user, $debt, ['account_id' => $this->wallet->id, 'amount' => '500', 'paid_on' => '2026-11-15']);
    $result = $this->sts->for($this->user, '2027-01-16');
    expect($result->owedTotal->cents())->toBe(100000)
        ->and($result->owed[0]['due_on'])->toBe('2026-12-15', 'shown from the first unpaid payment')
        ->and($this->sts->for($this->user, '2030-01-01')->owedTotal->cents())->toBe(250000, 'capped at what remains');
});

test('it can be negative: you\'d be short', function () {
    ($this->debt)(['principal' => '12,000', 'due_on' => '2026-11-25']);

    expect($this->sts->for($this->user, '2026-11-30')->safe->cents())->toBe(-200000);
});

test('the money is whole centavos, no rounding', function () {
    $this->user->forceFill(['safety_buffer_cents' => 1])->save();
    ($this->debt)(['principal' => '0.03', 'due_on' => '2026-11-25']);

    expect($this->sts->for($this->user, '2026-11-30')->safe)->toEqual(Money::fromCents(999996));
});

// The date: next payday

test('with no paydays it looks to the end of the month', function () {
    expect($this->sts->nextPayday($this->user))->toBe('2026-11-30');

    $this->travelTo(CarbonImmutable::parse('2026-11-30 09:00:00', 'Asia/Manila'));
    expect($this->sts->nextPayday($this->user))->toBe('2026-12-31');
});

test('with paydays it looks to the next one after today', function (array $days, string $today, string $expected) {
    ($this->setPaydays)($days);
    $this->travelTo(CarbonImmutable::parse($today.' 09:00:00', 'Asia/Manila'));

    expect($this->sts->nextPayday($this->user))->toBe($expected);
})->with([
    'before the 15th' => [[15, 30], '2026-11-10', '2026-11-15'],
    'on payday, the next one' => [[15, 30], '2026-11-15', '2026-11-30'],
    'after the last one → next month' => [[15, 30], '2026-11-30', '2026-12-15'],
    'day 30 in February → Feb 28' => [[15, 30], '2027-02-20', '2027-02-28'],
    'day 31 in November → Nov 30' => [[31], '2026-11-19', '2026-11-30'],
    'year end' => [[5], '2026-12-20', '2027-01-05'],
]);

test('"today" for the next payday is the user\'s today', function () {
    ($this->setPaydays)([20]);

    // 11 pm Nov 19 in UTC is already Nov 20 in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:00:00', 'UTC'));

    expect($this->sts->nextPayday($this->user))->toBe('2026-12-20');
});

test('paydays are set in Preferences', function () {
    $save = fn (string $paydays) => app(UpdatePreferences::class)->handle($this->user, [
        'timezone' => 'Asia/Manila', 'currency' => 'PHP', 'safety_buffer' => '0', 'payments_due_days' => '7', 'paydays' => $paydays,
    ]);

    $save('30, 15, 15');
    expect($this->user->fresh()->paydays)->toBe([15, 30]);

    $save('');
    expect($this->user->fresh()->paydays)->toBeNull();

    expect(fn () => $save('0'))->toThrow(ValidationException::class)
        ->and(fn () => $save('32'))->toThrow(ValidationException::class)
        ->and(fn () => $save('1, 8, 15, 22, 29'))->toThrow(ValidationException::class)
        ->and(fn () => $save('fifteenth'))->toThrow(ValidationException::class);
});

// On the Wants page

test('the Wants page shows safe-to-spend with its breakdown, and whether each want fits', function () {
    ($this->setPaydays)([15, 30]);
    ($this->debt)(['principal' => '2,000', 'due_on' => '2026-11-25']);
    app(CreateWant::class)->handle($this->user, ['title' => 'Serum', 'price' => '1,150']);
    app(CreateWant::class)->handle($this->user, ['title' => 'Phone', 'price' => '9,999']);

    Livewire::test('pages::wants.index')
        ->assertSee('Safe to spend until Mon, Nov 30')
        ->assertSee('₱8,000.00')
        ->assertSee('Owe Ana, due Nov 25')
        ->assertSee('Fits')
        ->assertSee('₱1,999.00 over')
        ->set('until', '2026-11-20')
        ->assertSee('₱10,000.00');
});

test('another user\'s accounts and debts never count', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Account::factory()->opening(99999900)->create();
    $theirContact = Contact::factory()->create();
    app(CreateDebt::class)->handle($other, ['contact_id' => $theirContact->id, 'direction' => 'i_owe', 'principal' => '500', 'started_on' => '2026-11-01', 'due_on' => '2026-11-20']);
    $this->actingAs($this->user);

    $result = $this->sts->for($this->user, '2026-11-30');

    expect($result->balance->cents())->toBe(1000000)->and($result->owed)->toBe([]);
});
