<?php

use App\Actions\PersonalCare\AddRoutineStep;
use App\Actions\PersonalCare\CreateConflictRule;
use App\Actions\PersonalCare\CreateIngredientTag;
use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\CreateStarterCareData;
use App\Actions\PersonalCare\DeleteIngredientTag;
use App\Actions\PersonalCare\MoveRoutineStep;
use App\Actions\PersonalCare\RemoveRoutineStep;
use App\Actions\PersonalCare\SyncIngredientTags;
use App\Actions\PersonalCare\UpdateIngredientTag;
use App\Actions\PersonalCare\UpdateProduct;
use App\Actions\PersonalCare\UpdateRoutineStep;
use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\PauseSchedule;
use App\Actions\Schedules\ResumeSchedule;
use App\Enums\Frequency;
use App\Enums\ScheduleLogStatus;
use App\Models\ConflictRule;
use App\Models\IngredientTag;
use App\Models\Product;
use App\Models\Routine;
use App\Models\RoutineStep;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Services\ConflictChecker;
use App\Support\Clash;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    app(CreateStarterCareData::class)->handle($this->user);
    $this->am = Routine::where('period', 'am')->sole();
    $this->pm = Routine::where('period', 'pm')->sole();
    $this->tag = fn (string $name) => IngredientTag::where('name', $name)->sole();

    $this->product = fn (string $name, string $ingredients = '', string $type = 'skincare') => app(CreateProduct::class)->handle($this->user, [
        'type' => $type, 'name' => $name, 'status' => 'in_use', 'ingredients_text' => $ingredients,
    ]);
    $this->step = fn (Routine $routine, Product $product, array $schedule = []) => app(AddRoutineStep::class)->handle($this->user, $routine, [
        'product_id' => $product->id, ...$schedule,
    ]);
    $this->rule = fn (string $a, string $b, string $scope = 'same_routine', ?string $note = null) => app(CreateConflictRule::class)->handle($this->user, [
        'tag_a_id' => ($this->tag)($a)->id, 'tag_b_id' => ($this->tag)($b)->id, 'scope' => $scope, 'note' => $note,
    ]);
    $this->clashesOn = fn (string $date) => array_map(
        fn (Clash $c) => [$c->rule->tagA->name.'+'.$c->rule->tagB->name, $c->period?->value],
        app(ConflictChecker::class)->onDate($this->user, $date),
    );
});

// Starter data (D-017, D-018)

test('every user gets one AM and one PM routine and the six starter groups, and nothing twice', function () {
    app(CreateStarterCareData::class)->handle($this->user);

    expect(Routine::orderBy('period')->get()->map(fn ($r) => [$r->period->value, $r->name])->all())->toBe([['am', 'Morning'], ['pm', 'Night']])
        ->and(IngredientTag::orderBy('name')->pluck('name')->all())->toBe(['AHA', 'BHA', 'Benzoyl peroxide', 'Niacinamide', 'Retinoid', 'Vitamin C'])
        ->and(ConflictRule::count())->toBe(0, 'no ready-made rules');
});

test('registering creates the routines and starter groups', function () {
    auth()->logout();
    $this->post(route('register.store'), ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'password', 'password_confirmation' => 'password']);

    $ana = User::where('email', 'ana@example.com')->sole();

    expect(Routine::forUser($ana)->count())->toBe(2)->and(IngredientTag::forUser($ana)->count())->toBe(6);
});

test('the backfill migration gives existing users their routines and groups, and tags their products', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $toner = Product::factory()->create(['ingredients_text' => 'Water, Glycolic Acid, Glycerin']);
    $this->actingAs($this->user);

    $migration = require database_path('migrations/2026_11_18_080211_add_routines_and_starter_tags_to_existing_users.php');
    $migration->up();

    expect(Routine::forUser($other)->count())->toBe(2)
        ->and(IngredientTag::forUser($other)->count())->toBe(6)
        ->and(Routine::forUser($this->user)->count())->toBe(2, 'no duplicates')
        ->and(IngredientTag::forUser($other)->where('name', 'AHA')->sole()->products()->withoutGlobalScope('user')->pluck('products.id')->all())->toBe([$toner->id]);
});

// Matching products to groups

test('a product joins a group when its ingredients contain a keyword, ignoring case', function () {
    $toner = ($this->product)('Glow toner', 'Aqua, GLYCOLIC ACID, Niacinamide');
    $cream = ($this->product)('Plain cream', 'Water, Glycerin');

    expect($toner->tags()->pluck('name')->sort()->values()->all())->toBe(['AHA', 'Niacinamide'])
        ->and($cream->tags()->count())->toBe(0)
        ->and(SyncIngredientTags::matches(null, ['retinol']))->toBeFalse();
});

test('changing a product\'s ingredients matches it again', function () {
    $serum = ($this->product)('Serum', 'Water');

    app(UpdateProduct::class)->handle($this->user, $serum, ['type' => 'skincare', 'name' => 'Serum', 'status' => 'in_use', 'ingredients_text' => 'Retinol 0.3%']);

    expect($serum->tags()->pluck('name')->all())->toBe(['Retinoid']);
});

test('adding or editing a group matches existing products again', function () {
    $mist = ($this->product)('Mist', 'Water, Centella Asiatica Extract');

    $cica = app(CreateIngredientTag::class)->handle($this->user, ['name' => 'Cica', 'keywords' => "Centella asiatica,\nmadecassoside"]);
    expect($cica->keywords)->toBe(['centella asiatica', 'madecassoside'])
        ->and($mist->tags()->pluck('name')->all())->toBe(['Cica']);

    app(UpdateIngredientTag::class)->handle($this->user, $cica, ['name' => 'Cica', 'keywords' => 'madecassoside']);
    expect($mist->tags()->count())->toBe(0);
});

test('invalid groups are rejected', function (array $input, string $field) {
    expect(fn () => app(CreateIngredientTag::class)->handle($this->user, $input))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });
})->with([
    'no name' => [['name' => '', 'keywords' => 'x'], 'name'],
    'duplicate name' => [['name' => 'AHA', 'keywords' => 'x'], 'name'],
    'no keywords' => [['name' => 'New', 'keywords' => ' , ,'], 'keywords'],
]);

test('deleting a group deletes the rules that use it', function () {
    ($this->rule)('AHA', 'Retinoid');

    app(DeleteIngredientTag::class)->handle($this->user, ($this->tag)('AHA'));

    expect(ConflictRule::count())->toBe(0);
});

// Conflict rules

test('a rule is stored with the smaller id first, so A–B and B–A are the same rule', function () {
    ($this->rule)('Retinoid', 'AHA');

    $rule = ConflictRule::sole();
    expect($rule->tag_a_id)->toBeLessThan($rule->tag_b_id)
        ->and(fn () => ($this->rule)('AHA', 'Retinoid'))->toThrow(ValidationException::class);

    // Same pair with another scope is a different rule.
    ($this->rule)('AHA', 'Retinoid', 'same_day');
    expect(ConflictRule::count())->toBe(2);
});

test('a rule needs two different groups of your own', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    app(CreateStarterCareData::class)->handle($other);
    $theirTag = IngredientTag::forUser($other)->first();
    $this->actingAs($this->user);

    expect(fn () => ($this->rule)('AHA', 'AHA'))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateConflictRule::class)->handle($this->user, ['tag_a_id' => ($this->tag)('AHA')->id, 'tag_b_id' => $theirTag->id, 'scope' => 'same_day']))
        ->toThrow(ValidationException::class);
});

// Routine steps

test('a new step goes at the end with a daily schedule from today', function () {
    $cleanser = ($this->product)('Cleanser');
    $sunscreen = ($this->product)('Sunscreen');

    ($this->step)($this->am, $cleanser);
    $second = ($this->step)($this->am, $sunscreen);

    $rule = ScheduleRule::where('schedulable_type', 'routine_step')->where('schedulable_id', $second->id)->sole();

    expect($this->am->steps()->pluck('position')->all())->toBe([1, 2])
        ->and($rule->frequency)->toBe(Frequency::Daily)
        ->and($rule->starts_on->toDateString())->toBe('2026-11-19');
});

test('steps can be moved up and down', function () {
    $a = ($this->step)($this->pm, ($this->product)('A'));
    $b = ($this->step)($this->pm, ($this->product)('B'));
    $c = ($this->step)($this->pm, ($this->product)('C'));

    app(MoveRoutineStep::class)->handle($this->user, $c, -1);
    expect($this->pm->steps()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id]);

    app(MoveRoutineStep::class)->handle($this->user, $a, -1); // already first: nothing happens
    app(MoveRoutineStep::class)->handle($this->user, $a, 1);
    expect($this->pm->steps()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);
});

test('editing a step\'s schedule keeps its history (versioned)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    $step = ($this->step)($this->pm, ($this->product)('Exfoliant'));
    app(MarkOccurrence::class)->handle($this->user, $step->currentRule(), '2026-11-10', ScheduleLogStatus::Done);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    app(UpdateRoutineStep::class)->handle($this->user, $step, ['product_id' => $step->product_id, 'frequency' => 'weekly', 'weekdays' => [2, 6]]);

    $rules = ScheduleRule::where('schedulable_id', $step->id)->where('schedulable_type', 'routine_step')->orderBy('starts_on')->get();
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->ends_on->toDateString())->toBe('2026-11-18')
        ->and($rules[1]->weekdays)->toBe([2, 6])
        ->and(ScheduleLog::sole()->schedule_rule_id)->toBe($rules[0]->id);
});

test('removing a step hides it but keeps its schedule and history', function () {
    $step = ($this->step)($this->am, ($this->product)('Toner'));
    app(MarkOccurrence::class)->handle($this->user, $step->currentRule(), '2026-11-19', ScheduleLogStatus::Done);

    app(RemoveRoutineStep::class)->handle($this->user, $step);

    expect($this->am->steps()->count())->toBe(0)
        ->and(RoutineStep::withTrashed()->count())->toBe(1)
        ->and(ScheduleRule::count())->toBe(1)
        ->and(ScheduleLog::count())->toBe(1);
});

test('a step must use your own skincare or makeup product', function (Closure $productFor) {
    $product = $productFor($this);

    expect(fn () => ($this->step)($this->am, $product))->toThrow(ValidationException::class);
    expect(RoutineStep::count())->toBe(0);
})->with([
    'a vitamin' => fn ($t) => ($t->product)('Vitamin C', '', 'vitamin'),
    'a deleted product' => function ($t) {
        $p = ($t->product)('Old');
        $p->delete();

        return $p;
    },
    'another user\'s product' => function ($t) {
        $other = User::factory()->create();
        $t->actingAs($other);
        $p = Product::factory()->create();
        $t->actingAs($t->user);

        return $p;
    },
]);

// Conflict checking

test('a same-routine rule is broken when two steps in one routine meet it', function () {
    ($this->rule)('AHA', 'Retinoid');
    ($this->step)($this->pm, ($this->product)('Glycolic toner', 'glycolic acid'));
    ($this->step)($this->pm, ($this->product)('Retinol serum', 'retinol'));

    expect(($this->clashesOn)('2026-11-19'))->toBe([['AHA+Retinoid', 'pm']]);
});

test('a same-routine rule isn\'t broken across AM and PM; a same-day rule is', function () {
    ($this->rule)('AHA', 'Retinoid', 'same_routine');
    ($this->rule)('Vitamin C', 'Retinoid', 'same_day');
    ($this->step)($this->am, ($this->product)('Glycolic toner', 'glycolic acid, ascorbic acid'));
    ($this->step)($this->pm, ($this->product)('Retinol serum', 'retinol'));

    expect(($this->clashesOn)('2026-11-19'))->toBe([['Retinoid+Vitamin C', null]]);
});

test('one product containing both groups doesn\'t count on its own', function () {
    ($this->rule)('AHA', 'BHA');
    ($this->step)($this->pm, ($this->product)('AHA/BHA toner', 'glycolic acid, salicylic acid'));

    expect(($this->clashesOn)('2026-11-19'))->toBe([]);
});

test('only steps scheduled that day count', function () {
    ($this->rule)('AHA', 'Retinoid');
    ($this->step)($this->pm, ($this->product)('Exfoliant', 'lactic acid'), ['frequency' => 'weekly', 'weekdays' => [2, 6]]); // Tue, Sat
    ($this->step)($this->pm, ($this->product)('Retinol', 'retinol'));

    expect(($this->clashesOn)('2026-11-19'))->toBe([])           // Thursday
        ->and(($this->clashesOn)('2026-11-21'))->toBe([['AHA+Retinoid', 'pm']]); // Saturday
});

test('paused and removed steps don\'t count', function () {
    ($this->rule)('AHA', 'Retinoid');
    $exfoliant = ($this->step)($this->pm, ($this->product)('Exfoliant', 'lactic acid'));
    $retinol = ($this->step)($this->pm, ($this->product)('Retinol', 'retinol'));

    app(PauseSchedule::class)->handle($this->user, $exfoliant->currentRule());
    expect(($this->clashesOn)('2026-11-19'))->toBe([]);

    app(ResumeSchedule::class)->handle($this->user, $exfoliant->currentRule());
    app(RemoveRoutineStep::class)->handle($this->user, $retinol);
    expect(($this->clashesOn)('2026-11-19'))->toBe([]);
});

test('the save warning lists the next 4 weeks of clashes for that step only', function () {
    ($this->rule)('AHA', 'Retinoid', 'same_routine', 'not on the same night');
    ($this->rule)('Niacinamide', 'Vitamin C', 'same_routine');
    ($this->step)($this->am, ($this->product)('Niacinamide serum', 'niacinamide'));
    ($this->step)($this->am, ($this->product)('Vitamin C serum', 'ascorbic acid'));
    $retinol = ($this->step)($this->pm, ($this->product)('Retinol', 'retinol'));
    $exfoliant = ($this->step)($this->pm, ($this->product)('Exfoliant', 'lactic acid'), ['frequency' => 'weekly', 'weekdays' => [2, 6]]);

    $found = app(ConflictChecker::class)->upcomingFor($this->user, $exfoliant);

    expect($found)->toHaveCount(1, 'the AM clash doesn\'t involve this step')
        ->and($found[0]['clash']->summary())->toBe('AHA + Retinoid in the PM routine: Exfoliant and Retinol')
        ->and($found[0]['dates'])->toHaveCount(8)
        ->and($found[0]['dates'][0])->toBe('2026-11-21')
        ->and(app(ConflictChecker::class)->upcomingFor($this->user, $retinol)[0]['dates'])->toHaveCount(8);
});
