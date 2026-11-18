<?php

use App\Actions\Finance\CreateDebt;
use App\Enums\DebtStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->bank = Account::factory()->create(['name' => 'Bank']);
    $this->gcash = Account::factory()->create(['name' => 'GCash']);
    $this->ana = Contact::factory()->create(['name' => 'Ana']);

    $this->debt = app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ana->id, 'direction' => 'i_owe', 'principal' => '500',
        'account_id' => $this->gcash->id, 'started_on' => '2026-11-10', 'due_on' => '2026-11-20',
    ]);
});

test('the window opens pre-filled with what remains, the debt\'s account and today', function () {
    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $this->debt->id)
        ->assertSet('debtId', $this->debt->id)
        ->assertSet('amount', '500.00')
        ->assertSet('account_id', (string) $this->gcash->id)
        ->assertSet('paid_on', '2026-11-19')
        ->assertSee('You pay Ana · ₱500.00 left');
});

test('without an account on the debt, the first account is chosen', function () {
    $noAccount = app(CreateDebt::class)->handle($this->user, [
        'contact_id' => $this->ana->id, 'direction' => 'owed_to_me', 'principal' => '100', 'started_on' => '2026-11-10',
    ]);

    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $noAccount->id)
        ->assertSet('account_id', (string) $this->bank->id)
        ->assertSee('Ana pays you back · ₱100.00 left');
});

test('paying in full settles the debt, completes its task and tells the page', function () {
    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $this->debt->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('debt-paid')
        ->assertSet('debtId', null);

    expect($this->debt->fresh()->status)->toBe(DebtStatus::Settled)
        ->and(Task::where('source_type', 'debt')->sole()->completed_at)->not->toBeNull();
});

test('a partial payment leaves the debt open', function () {
    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $this->debt->id)
        ->set('amount', '200')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->debt->fresh()->remaining()->cents())->toBe(30000);
});

test('overpaying shows an error and saves nothing', function () {
    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $this->debt->id)
        ->set('amount', '500.01')
        ->call('save')
        ->assertHasErrors(['amount'])
        ->assertSee('Only ₱500.00 is left to pay.');

    expect(DebtPayment::count())->toBe(0);
});

test('another user\'s debt returns 404', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirContact = Contact::factory()->create();
    $theirs = app(CreateDebt::class)->handle($other, [
        'contact_id' => $theirContact->id, 'direction' => 'i_owe', 'principal' => '100', 'started_on' => '2026-11-10',
    ]);
    $this->actingAs($this->user);

    Livewire::test('finance.record-payment')
        ->dispatch('record-payment', debtId: $theirs->id)
        ->assertNotFound();

    expect(Debt::forUser($other)->sole()->status)->toBe(DebtStatus::Open);
});

// Where it's used

test('ticking a debt task on the Tasks page opens the payment window instead', function () {
    $task = Task::where('source_type', 'debt')->sole();

    Livewire::test('pages::tasks.index')
        ->call('toggle', $task->id)
        ->assertDispatched('record-payment', debtId: $this->debt->id);

    expect($task->fresh()->isCompleted())->toBeFalse();
});

test('a settled debt\'s task can\'t be unticked on the Tasks page', function () {
    Livewire::test('finance.record-payment')->dispatch('record-payment', debtId: $this->debt->id)->call('save');
    $task = Task::where('source_type', 'debt')->sole();

    Livewire::withQueryParams(['filter' => 'done'])
        ->test('pages::tasks.index')
        ->call('toggle', $task->id)
        ->assertNotDispatched('record-payment');

    expect($task->fresh()->isCompleted())->toBeTrue();
});

test('Today\'s Payments Due lists the debt with a Record payment button', function () {
    Livewire::test('today.payments-due')
        ->assertSee('Pay Ana ₱500.00')
        ->assertSee('Due Fri, Nov 20')
        ->assertSee('1 due')
        ->assertSee('Record payment');
});

test('Payments Due refreshes after a payment', function () {
    Livewire::test('finance.record-payment')->dispatch('record-payment', debtId: $this->debt->id)->call('save');

    Livewire::test('today.payments-due')
        ->assertDontSee('Pay Ana')
        ->assertSee('Nothing due in the next 7 days.');
});

test('debt tasks are left out of Today\'s Tasks section', function () {
    // Make the debt due today, so its task would otherwise be listed.
    $this->debt->forceFill(['due_on' => '2026-11-19'])->save();
    Task::where('source_type', 'debt')->update(['due_on' => '2026-11-19']);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'Water the plants']);

    Livewire::test('today.tasks')
        ->assertSee('Water the plants')
        ->assertDontSee('Pay Ana');
});

test('the Today page includes the payment window', function () {
    $this->get(route('today'))->assertOk()->assertSee('Pay Ana ₱500.00')->assertSeeLivewire('finance.record-payment');
});
