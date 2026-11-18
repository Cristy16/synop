<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Services\TransactionList;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    $this->list = app(TransactionList::class);

    $this->wallet = Account::factory()->create(['name' => 'Wallet']);
    $this->gcash = Account::factory()->create(['name' => 'GCash']);
    $this->food = TransactionCategory::factory()->create(['name' => 'Food']);

    $this->notes = fn (array $filters) => $this->list->for($this->user, $filters)->getCollection()->pluck('note')->all();
});

test('one month at a time, newest first', function () {
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-10-31', 'note' => 'oct 31']);
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-01', 'note' => 'nov 1']);
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-30', 'note' => 'nov 30']);
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-12-01', 'note' => 'dec 1']);

    expect(($this->notes)(['month' => '2026-11']))->toBe(['nov 30', 'nov 1'])
        ->and(($this->notes)(['month' => '2026-10']))->toBe(['oct 31']);
});

test('no or invalid month means this month in the user\'s timezone', function (?string $month) {
    // 11 pm Oct 31 in UTC is already Nov 1 in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 23:00:00', 'UTC'));

    expect($this->list->month($this->user, $month))->toBe('2026-11');
})->with([null, '', '2026-13', 'nonsense', '2026-1']);

test('filter by account includes transfers into it', function () {
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-05', 'note' => 'wallet spend']);
    Transaction::factory()->transferTo($this->gcash)->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-06', 'note' => 'top up']);
    Transaction::factory()->create(['account_id' => $this->gcash->id, 'occurred_on' => '2026-11-07', 'note' => 'gcash spend']);

    expect(($this->notes)(['month' => '2026-11', 'account_id' => $this->gcash->id]))->toBe(['gcash spend', 'top up'])
        ->and(($this->notes)(['month' => '2026-11', 'account_id' => $this->wallet->id]))->toBe(['top up', 'wallet spend']);
});

test('filter by type and by category', function () {
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'category_id' => $this->food->id, 'occurred_on' => '2026-11-05', 'note' => 'lunch']);
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-06', 'note' => 'other spend']);
    Transaction::factory()->income()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-07', 'note' => 'allowance']);

    expect(($this->notes)(['month' => '2026-11', 'type' => 'income']))->toBe(['allowance'])
        ->and(($this->notes)(['month' => '2026-11', 'category_id' => $this->food->id]))->toBe(['lunch'])
        ->and(($this->notes)(['month' => '2026-11', 'type' => 'bogus']))->toHaveCount(3);
});

test('deleted transactions are not listed', function () {
    Transaction::factory()->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-05'])->delete();

    expect(($this->notes)(['month' => '2026-11']))->toBe([]);
});

test('50 per page', function () {
    Transaction::factory()->count(51)->create(['account_id' => $this->wallet->id, 'occurred_on' => '2026-11-05']);

    expect($this->list->for($this->user, ['month' => '2026-11'])->count())->toBe(50)
        ->and($this->list->for($this->user, ['month' => '2026-11'], page: 2)->count())->toBe(1);
});

test('another user\'s transactions never appear, even when filtering by their account', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Account::factory()->create();
    Transaction::factory()->create(['account_id' => $theirs->id, 'occurred_on' => '2026-11-05', 'type' => TransactionType::Expense]);
    $this->actingAs($this->user);

    expect(($this->notes)(['month' => '2026-11']))->toBe([])
        ->and(($this->notes)(['month' => '2026-11', 'account_id' => $theirs->id]))->toBe([]);
});
