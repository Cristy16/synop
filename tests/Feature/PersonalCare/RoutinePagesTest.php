<?php

use App\Actions\PersonalCare\AddRoutineStep;
use App\Actions\PersonalCare\CreateConflictRule;
use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\CreateStarterCareData;
use App\Actions\PersonalCare\DeleteConflictRule;
use App\Actions\PersonalCare\DeleteIngredientTag;
use App\Actions\PersonalCare\MoveRoutineStep;
use App\Actions\PersonalCare\RemoveRoutineStep;
use App\Actions\PersonalCare\UpdateIngredientTag;
use App\Actions\PersonalCare\UpdateRoutineStep;
use App\Models\ConflictRule;
use App\Models\IngredientTag;
use App\Models\Product;
use App\Models\Routine;
use App\Models\RoutineStep;
use App\Models\ScheduleLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    app(CreateStarterCareData::class)->handle($this->user);
    $this->am = Routine::where('period', 'am')->sole();
    $this->pm = Routine::where('period', 'pm')->sole();
    $this->product = fn (string $name, string $ingredients = '') => app(CreateProduct::class)->handle($this->user, [
        'type' => 'skincare', 'name' => $name, 'status' => 'in_use', 'ingredients_text' => $ingredients,
    ]);
    $this->step = fn (Routine $r, Product $p, array $schedule = []) => app(AddRoutineStep::class)->handle($this->user, $r, ['product_id' => $p->id, ...$schedule]);
    $this->rule = fn (string $a, string $b, ?string $note = null) => app(CreateConflictRule::class)->handle($this->user, [
        'tag_a_id' => IngredientTag::where('name', $a)->sole()->id, 'tag_b_id' => IngredientTag::where('name', $b)->sole()->id,
        'scope' => 'same_routine', 'note' => $note,
    ]);
});

test('the Routines and Conflicts tabs load', function (string $route) {
    $this->get(route($route))->assertOk()->assertSee(route('care.routines'));

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['care.routines', 'care.conflicts']);

// Routines tab

test('a step is added from the Routines tab', function () {
    $cleanser = ($this->product)('Cleanser');

    Livewire::test('pages::care.routines')
        ->call('addStep', $this->am->id)
        ->set('product_id', (string) $cleanser->id)
        ->set('note', 'Massage for 60 seconds')
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSet('warnings', [])
        ->assertSee('Cleanser')
        ->assertSee('↻ Every day');

    expect(RoutineStep::sole()->routine_id)->toBe($this->am->id);
});

test('saving a clashing step saves it and warns, with the days and the note', function () {
    ($this->rule)('AHA', 'Retinoid', 'not on the same night');
    ($this->step)($this->pm, ($this->product)('Retinol serum', 'retinol'));
    $exfoliant = ($this->product)('Exfoliant', 'glycolic acid');

    Livewire::test('pages::care.routines')
        ->call('addStep', $this->pm->id)
        ->set('product_id', (string) $exfoliant->id)
        ->set('frequency', 'weekly')
        ->set('weekdays', ['2', '6'])
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSee('Saved, but this clashes with your rules')
        ->assertSee('AHA + Retinoid in the PM routine: Exfoliant and Retinol serum')
        ->assertSee('on Tue, Sat (next: Nov 21)')
        ->assertSee('not on the same night')
        ->call('dismissWarnings')
        ->assertDontSee('Saved, but this clashes');

    expect(RoutineStep::count())->toBe(2);
});

test('step errors show on the form', function () {
    Livewire::test('pages::care.routines')
        ->call('addStep', $this->am->id)
        ->call('saveStep')
        ->assertHasErrors(['product_id']);
});

test('a step can be edited, moved and removed from the tab', function () {
    $a = ($this->step)($this->am, ($this->product)('A'));
    $b = ($this->step)($this->am, ($this->product)('B'));

    Livewire::test('pages::care.routines')
        ->call('editStep', $b->id)
        ->assertSet('frequency', 'daily')
        ->set('frequency', 'every_n_days')
        ->set('interval', '3')
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSee('↻ Every 3 days')
        ->call('moveStep', $b->id, -1)
        ->call('removeStep', $a->id);

    expect($this->am->steps()->pluck('id')->all())->toBe([$b->id]);
});

// Conflicts tab

test('groups and rules are managed from the Conflicts tab', function () {
    Livewire::test('pages::care.conflicts')
        ->assertSee('AHA')
        ->assertSee('glycolic acid')
        ->call('createTag')
        ->set('name', 'Cica')
        ->set('keywords', "centella asiatica\nmadecassoside")
        ->call('saveTag')
        ->assertHasNoErrors()
        ->assertSee('Cica')
        ->call('createRule')
        ->set('tag_a_id', (string) IngredientTag::where('name', 'Retinoid')->sole()->id)
        ->set('tag_b_id', (string) IngredientTag::where('name', 'AHA')->sole()->id)
        ->set('scope', 'same_routine')
        ->set('note', 'not on the same night')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSee('AHA ✕ Retinoid')
        ->assertSee('not on the same night');

    $rule = ConflictRule::sole();

    Livewire::test('pages::care.conflicts')->call('deleteRule', $rule->id);
    expect(ConflictRule::count())->toBe(0);
});

test('a duplicate rule shows an error', function () {
    ($this->rule)('AHA', 'Retinoid');

    Livewire::test('pages::care.conflicts')
        ->call('createRule')
        ->set('tag_a_id', (string) IngredientTag::where('name', 'AHA')->sole()->id)
        ->set('tag_b_id', (string) IngredientTag::where('name', 'Retinoid')->sole()->id)
        ->call('saveRule')
        ->assertHasErrors(['tag_b_id']);
});

// Today → Personal Care

test('Today lists today\'s AM and PM steps in order and warns about broken rules', function () {
    ($this->rule)('AHA', 'Retinoid', 'not on the same night');
    ($this->step)($this->am, ($this->product)('Cleanser'));
    ($this->step)($this->am, ($this->product)('Sunscreen'));
    ($this->step)($this->pm, ($this->product)('Retinol serum', 'retinol'));
    ($this->step)($this->pm, ($this->product)('Exfoliant', 'lactic acid'));
    ($this->step)($this->pm, ($this->product)('Mask'), ['frequency' => 'weekly', 'weekdays' => [1]]); // Mondays only

    Livewire::test('today.personal-care')
        ->assertSeeInOrder(['AM', 'Cleanser', 'Sunscreen', 'PM', 'Retinol serum', 'Exfoliant'])
        ->assertDontSee('Mask')
        ->assertSee('0 of 4 done')
        ->assertSee('Today breaks 1 of your rules')
        ->assertSee('AHA + Retinoid in the PM routine')
        ->assertSee('not on the same night');
});

test('steps are ticked, skipped and un-ticked from Today', function () {
    $cleanser = ($this->step)($this->am, ($this->product)('Cleanser'));
    $toner = ($this->step)($this->am, ($this->product)('Toner'));

    $section = Livewire::test('today.personal-care')
        ->call('toggleStep', $cleanser->currentRule()->id)
        ->assertSee('1 of 2 done')
        ->call('skipStep', $toner->currentRule()->id)
        ->assertSee('1 of 1 done')
        ->assertSee('Skipped');

    expect(ScheduleLog::orderBy('id')->pluck('status')->map->value->all())->toBe(['done', 'skipped']);

    $section->call('unskipStep', $toner->currentRule()->id)->call('toggleStep', $cleanser->currentRule()->id);
    expect(ScheduleLog::count())->toBe(0);
});

test('Today has nothing to show without steps', function () {
    Livewire::test('today.personal-care')->assertSee('No routine steps today.');
});

// Cross-user

test('routines, steps, groups and rules are invisible to another user', function (Closure $make) {
    assertInvisibleToOtherUser(function (User $owner) use ($make) {
        app(CreateStarterCareData::class)->handle($owner);

        return $make($owner);
    });
})->with([
    'routine' => fn (User $u) => Routine::where('period', 'am')->sole(),
    'group' => fn (User $u) => IngredientTag::where('name', 'AHA')->sole(),
    'rule' => fn (User $u) => app(CreateConflictRule::class)->handle($u, [
        'tag_a_id' => IngredientTag::where('name', 'AHA')->sole()->id, 'tag_b_id' => IngredientTag::where('name', 'BHA')->sole()->id, 'scope' => 'same_day',
    ]),
    'step' => fn (User $u) => app(AddRoutineStep::class)->handle($u, Routine::where('period', 'am')->sole(), [
        'product_id' => app(CreateProduct::class)->handle($u, ['type' => 'skincare', 'name' => 'Toner', 'status' => 'in_use'])->id,
    ]),
]);

test('another user gets a 404 from every routine and rule action', function (Closure $call) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    app(CreateStarterCareData::class)->handle($owner);
    $routine = Routine::where('period', 'am')->sole();
    $product = app(CreateProduct::class)->handle($owner, ['type' => 'skincare', 'name' => 'Toner', 'status' => 'in_use']);
    $step = app(AddRoutineStep::class)->handle($owner, $routine, ['product_id' => $product->id]);
    $tag = IngredientTag::where('name', 'AHA')->sole();
    $rule = app(CreateConflictRule::class)->handle($owner, ['tag_a_id' => $tag->id, 'tag_b_id' => IngredientTag::where('name', 'BHA')->sole()->id, 'scope' => 'same_day']);

    $this->actingAs($this->user);
    $records = ['routine' => $routine, 'step' => $step, 'tag' => $tag, 'rule' => $rule, 'product' => $product];

    expect(fn () => $call($this->user, $records))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });
})->with([
    'add step' => fn (User $u, array $r) => app(AddRoutineStep::class)->handle($u, $r['routine'], ['product_id' => $r['product']->id]),
    'edit step' => fn (User $u, array $r) => app(UpdateRoutineStep::class)->handle($u, $r['step'], ['product_id' => $r['product']->id]),
    'move step' => fn (User $u, array $r) => app(MoveRoutineStep::class)->handle($u, $r['step'], 1),
    'remove step' => fn (User $u, array $r) => app(RemoveRoutineStep::class)->handle($u, $r['step']),
    'edit group' => fn (User $u, array $r) => app(UpdateIngredientTag::class)->handle($u, $r['tag'], ['name' => 'X', 'keywords' => 'x']),
    'delete group' => fn (User $u, array $r) => app(DeleteIngredientTag::class)->handle($u, $r['tag']),
    'delete rule' => fn (User $u, array $r) => app(DeleteConflictRule::class)->handle($u, $r['rule']),
]);

test('another user\'s ids return 404 on the pages', function (string $page, string $method, string $what) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    app(CreateStarterCareData::class)->handle($owner);
    $routine = Routine::where('period', 'am')->sole();
    $step = app(AddRoutineStep::class)->handle($owner, $routine, [
        'product_id' => app(CreateProduct::class)->handle($owner, ['type' => 'skincare', 'name' => 'Toner', 'status' => 'in_use'])->id,
    ]);
    $ids = ['routine' => $routine->id, 'step' => $step->id, 'rule' => $step->currentRule()->id, 'tag' => IngredientTag::where('name', 'AHA')->sole()->id];
    $this->actingAs($this->user);

    Livewire::test($page)->call($method, $ids[$what])->assertNotFound();

    $this->actingAs($owner);
    expect(ScheduleLog::count())->toBe(0)->and(RoutineStep::count())->toBe(1);
})->with([
    ['pages::care.routines', 'addStep', 'routine'],
    ['pages::care.routines', 'editStep', 'step'],
    ['pages::care.routines', 'removeStep', 'step'],
    ['pages::care.conflicts', 'editTag', 'tag'],
    ['today.personal-care', 'toggleStep', 'rule'],
    ['today.personal-care', 'skipStep', 'rule'],
]);

test('locked ids can\'t be changed from the browser', function (string $page, string $property) {
    Livewire::test($page)->set($property, 1);
})->with([
    ['pages::care.routines', 'routineId'],
    ['pages::care.routines', 'editingStepId'],
    ['pages::care.conflicts', 'editingTagId'],
])->throws(CannotUpdateLockedPropertyException::class);
