<?php

use App\Actions\PersonalCare\AddVitaminDose;
use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\RemoveVitaminDose;
use App\Actions\PersonalCare\RestockVitamin;
use App\Actions\PersonalCare\TakeVitaminDose;
use App\Actions\PersonalCare\UpdateVitaminDose;
use App\Enums\ScheduleLogStatus;
use App\Models\Product;
use App\Models\ScheduleLog;
use App\Models\User;
use App\Models\VitaminDose;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->vitamin = fn (string $name = 'Vitamin C 500mg', ?int $left = 30, ?int $at = 10) => app(CreateProduct::class)->handle($this->user, [
        'type' => 'vitamin', 'name' => $name, 'status' => 'in_use', 'units_left' => $left, 'low_stock_threshold' => $at,
    ]);
    $this->dose = fn (Product $p, string $time = '08:00', array $o = []) => app(AddVitaminDose::class)->handle($this->user, ['product_id' => $p->id, 'dose_time' => $time, ...$o]);
});

test('the Vitamins tab loads', function () {
    $this->get(route('care.vitamins'))->assertOk()->assertSee(route('care.vitamins'));

    auth()->logout();
    $this->get(route('care.vitamins'))->assertRedirect(route('login'));
});

test('a dose time is added, edited and removed from the Vitamins tab', function () {
    $vitamin = ($this->vitamin)();

    $page = Livewire::test('pages::care.vitamins')
        ->assertSee('Vitamin C 500mg')
        ->assertSee('30 tablets left')
        ->call('addDose', $vitamin->id)
        ->set('dose_time', '20:30')
        ->set('units', '2')
        ->set('meal_note', 'After dinner')
        ->call('saveDose')
        ->assertHasNoErrors()
        ->assertSee('8:30 PM · 2 tablets')
        ->assertSee('After dinner');

    $dose = VitaminDose::sole();

    $page->call('editDose', $dose->id)
        ->assertSet('dose_time', '20:30')
        ->set('frequency', 'weekly')
        ->set('weekdays', ['1', '3', '5'])
        ->call('saveDose')
        ->assertHasNoErrors()
        ->assertSee('↻ Mon, Wed, Fri')
        ->call('removeDose', $dose->id);

    expect(VitaminDose::count())->toBe(0);
});

test('tablets are added from the tab, and the low badge goes away', function () {
    $vitamin = ($this->vitamin)('Zinc', 5, 10);

    Livewire::test('pages::care.vitamins')
        ->assertSee('Running low')
        ->call('restock', $vitamin->id)
        ->set('restock_units', '60')
        ->call('saveRestock')
        ->assertHasNoErrors()
        ->assertSee('65 tablets left')
        ->assertDontSee('Running low');
});

test('Today lists today\'s doses in time order, and ticking takes tablets off', function () {
    $c = ($this->vitamin)('Vitamin C', 30);
    $d = ($this->vitamin)('Vitamin D', 20);
    $night = ($this->dose)($c, '21:00');
    ($this->dose)($d, '07:30', ['meal_note' => 'With breakfast']);
    ($this->dose)($d, '12:00', ['frequency' => 'weekly', 'weekdays' => [1]]); // Mondays only

    $section = Livewire::test('today.vitamins')
        ->assertSeeInOrder(['Vitamin D', '7:30 AM', 'With breakfast', '20 left', 'Vitamin C', '9:00 PM', '30 left'])
        ->assertDontSee('12:00 PM')
        ->assertSee('0 of 2 done')
        ->call('toggleDose', $night->currentRule()->id)
        ->assertSee('1 of 2 done')
        ->assertSee('29 left');

    expect($c->fresh()->units_left)->toBe(29);

    $section->call('toggleDose', $night->currentRule()->id);
    expect($c->fresh()->units_left)->toBe(30);
});

test('skip and undo on Today', function () {
    $dose = ($this->dose)(($this->vitamin)());

    Livewire::test('today.vitamins')
        ->call('skipDose', $dose->currentRule()->id)
        ->assertSee('Skipped')
        ->call('unskipDose', $dose->currentRule()->id)
        ->assertSee('Skip');

    expect(ScheduleLog::count())->toBe(0);
});

test('Today has nothing to show without doses', function () {
    Livewire::test('today.vitamins')->assertSee('No vitamins to take today.');
});

// Cross-user

test('a dose is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => app(AddVitaminDose::class)->handle($owner, [
        'product_id' => app(CreateProduct::class)->handle($owner, ['type' => 'vitamin', 'name' => 'Zinc', 'status' => 'in_use'])->id,
        'dose_time' => '08:00',
    ]));
});

test('another user gets a 404 from every vitamin action', function (Closure $call) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $vitamin = app(CreateProduct::class)->handle($owner, ['type' => 'vitamin', 'name' => 'Zinc', 'status' => 'in_use', 'units_left' => 30]);
    $dose = app(AddVitaminDose::class)->handle($owner, ['product_id' => $vitamin->id, 'dose_time' => '08:00']);
    $records = ['vitamin' => $vitamin, 'dose' => $dose, 'rule' => $dose->currentRule()];
    $this->actingAs($this->user);

    expect(fn () => $call($this->user, $records))->toThrow(function (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    });

    $this->actingAs($owner);
    expect($vitamin->fresh()->units_left)->toBe(30)->and(ScheduleLog::count())->toBe(0)->and(VitaminDose::count())->toBe(1);
})->with([
    'edit dose' => fn (User $u, array $r) => app(UpdateVitaminDose::class)->handle($u, $r['dose'], ['product_id' => $r['vitamin']->id, 'dose_time' => '09:00']),
    'remove dose' => fn (User $u, array $r) => app(RemoveVitaminDose::class)->handle($u, $r['dose']),
    'take dose' => fn (User $u, array $r) => app(TakeVitaminDose::class)->handle($u, $r['rule'], ScheduleLogStatus::Done),
    'restock' => fn (User $u, array $r) => app(RestockVitamin::class)->handle($u, $r['vitamin'], 60),
]);

test('a dose can\'t use another user\'s vitamin', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $theirs = app(CreateProduct::class)->handle($owner, ['type' => 'vitamin', 'name' => 'Zinc', 'status' => 'in_use']);
    $this->actingAs($this->user);

    expect(fn () => app(AddVitaminDose::class)->handle($this->user, ['product_id' => $theirs->id, 'dose_time' => '08:00']))
        ->toThrow(ValidationException::class);
});

test('another user\'s ids return 404 on the pages', function (string $page, string $method, string $what) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $vitamin = app(CreateProduct::class)->handle($owner, ['type' => 'vitamin', 'name' => 'Zinc', 'status' => 'in_use', 'units_left' => 30]);
    $dose = app(AddVitaminDose::class)->handle($owner, ['product_id' => $vitamin->id, 'dose_time' => '08:00']);
    $ids = ['vitamin' => $vitamin->id, 'dose' => $dose->id, 'rule' => $dose->currentRule()->id];
    $this->actingAs($this->user);

    Livewire::test($page)->call($method, $ids[$what])->assertNotFound();

    $this->actingAs($owner);
    expect($vitamin->fresh()->units_left)->toBe(30)->and(ScheduleLog::count())->toBe(0);
})->with([
    ['pages::care.vitamins', 'addDose', 'vitamin'],
    ['pages::care.vitamins', 'editDose', 'dose'],
    ['pages::care.vitamins', 'removeDose', 'dose'],
    ['pages::care.vitamins', 'restock', 'vitamin'],
    ['today.vitamins', 'toggleDose', 'rule'],
    ['today.vitamins', 'skipDose', 'rule'],
]);

test('another user\'s vitamins never show', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $theirs = app(CreateProduct::class)->handle($owner, ['type' => 'vitamin', 'name' => 'Their secret zinc', 'status' => 'in_use']);
    app(AddVitaminDose::class)->handle($owner, ['product_id' => $theirs->id, 'dose_time' => '08:00']);
    $this->actingAs($this->user);

    $this->get(route('care.vitamins'))->assertDontSee('Their secret zinc');
    $this->get(route('today'))->assertDontSee('Their secret zinc');
});

test('locked ids can\'t be changed from the browser', function (string $property) {
    Livewire::test('pages::care.vitamins')->set($property, 1);
})->with(['productId', 'editingDoseId', 'restockId'])->throws(CannotUpdateLockedPropertyException::class);
