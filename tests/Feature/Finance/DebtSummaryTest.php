<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Finance\RecordDebtPayment;
use App\Models\Account;
use App\Models\Contact;
use App\Models\User;
use App\Services\DebtSummary;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila, payments_due_days = 7
    $this->actingAs($this->user);
    // Thursday Nov 19, 9:00 am in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->wallet = Account::factory()->opening(1000000)->create();
    $this->ben = Contact::factory()->create(['name' => 'Ben']);
    $this->ana = Contact::factory()->create(['name' => 'Ana']);
    $this->summary = app(DebtSummary::class);

    $this->debt = fn (Contact $c, string $direction, string $principal, ?string $due = null) => app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $c->id, 'direction' => $direction, 'principal' => $principal,
        'account_id' => $this->wallet->id, 'started_on' => '2026-11-01', 'due_on' => $due,
    ]);
    $this->pay = fn ($debt, string $amount) => app(RecordDebtPayment::class)->handle($this->user, $debt, [
        'account_id' => $this->wallet->id, 'amount' => $amount, 'paid_on' => '2026-11-10',
    ]);
});

test('totals add up what remains on open debts, each way', function () {
    $lent = ($this->debt)($this->ben, 'owed_to_me', '1,000');
    ($this->pay)($lent, '300');
    ($this->debt)($this->ana, 'owed_to_me', '500');
    ($this->debt)($this->ana, 'i_owe', '250.50');
    $settled = ($this->debt)($this->ben, 'i_owe', '100');
    ($this->pay)($settled, '100');

    $totals = $this->summary->totals($this->user);

    expect($totals['owed_to_me']->cents())->toBe(120000)
        ->and($totals['i_owe']->cents())->toBe(25050);
});

test('totals are zero with no debts', function () {
    $totals = $this->summary->totals($this->user);

    expect($totals['owed_to_me']->isZero())->toBeTrue()->and($totals['i_owe']->isZero())->toBeTrue();
});

test('due soon lists open debts due within the window or overdue, soonest first', function () {
    ($this->debt)($this->ben, 'owed_to_me', '100', '2026-11-26');  // today + 7: in
    ($this->debt)($this->ben, 'owed_to_me', '100', '2026-11-27');  // today + 8: out
    ($this->debt)($this->ana, 'i_owe', '100', '2026-11-19');        // today: in
    ($this->debt)($this->ana, 'i_owe', '100', '2026-11-05');        // overdue: in
    ($this->debt)($this->ana, 'i_owe', '100');                      // no due date: out
    $paid = ($this->debt)($this->ben, 'owed_to_me', '100', '2026-11-20');
    ($this->pay)($paid, '100');                                      // settled: out

    expect($this->summary->dueSoon($this->user)->map(fn ($d) => $d->due_on->toDateString())->all())
        ->toBe(['2026-11-05', '2026-11-19', '2026-11-26']);
});

test('the due-soon window follows the user\'s setting', function () {
    $this->user->forceFill(['payments_due_days' => 30])->save();
    ($this->debt)($this->ben, 'owed_to_me', '100', '2026-12-15');

    expect($this->summary->dueSoon($this->user))->toHaveCount(1);
});

test('due-soon items know what remains without extra queries', function () {
    $debt = ($this->debt)($this->ben, 'owed_to_me', '1,000', '2026-11-20');
    ($this->pay)($debt, '250');

    $item = $this->summary->dueSoon($this->user)->sole();

    expect($item->remaining()->cents())->toBe(75000)
        ->and($item->getAttributes())->toHaveKey('paid_cents');
});

test('by contact groups debts with each contact\'s net', function () {
    ($this->debt)($this->ben, 'owed_to_me', '1,000');
    ($this->debt)($this->ben, 'i_owe', '300');
    ($this->debt)($this->ana, 'i_owe', '500');
    Contact::factory()->create(['name' => 'Carlo']); // no debts

    $groups = collect($this->summary->byContact($this->user));

    expect($groups->map(fn ($g) => [$g['contact']->name, $g['debts']->count(), $g['net']->cents()])->all())->toBe([
        ['Ana', 1, -50000],
        ['Ben', 2, 70000],
        ['Carlo', 0, 0],
    ]);
});

test('settled debts are hidden unless asked for', function () {
    $debt = ($this->debt)($this->ben, 'owed_to_me', '100');
    ($this->pay)($debt, '100');

    $hidden = collect($this->summary->byContact($this->user))->firstWhere('contact.name', 'Ben');
    $shown = collect($this->summary->byContact($this->user, includeSettled: true))->firstWhere('contact.name', 'Ben');

    expect($hidden['debts'])->toHaveCount(0)->and($shown['debts'])->toHaveCount(1);
});

test('another user\'s debts never appear', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirContact = Contact::factory()->create();
    $theirAccount = Account::factory()->create();
    app(CreateDebt::class)->handle($other, [
        'contact_id' => $theirContact->id, 'direction' => 'owed_to_me', 'principal' => '999',
        'account_id' => $theirAccount->id, 'started_on' => '2026-11-01', 'due_on' => '2026-11-20',
    ]);
    $this->actingAs($this->user);

    expect($this->summary->totals($this->user)['owed_to_me']->isZero())->toBeTrue()
        ->and($this->summary->dueSoon($this->user))->toHaveCount(0)
        ->and(collect($this->summary->byContact($this->user))->pluck('contact.name')->all())->toBe(['Ana', 'Ben']);
});
