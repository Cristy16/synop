<?php

use App\Actions\PersonalCare\AddVitaminDose;
use App\Actions\PersonalCare\CreateProduct;
use App\Actions\PersonalCare\RemoveVitaminDose;
use App\Actions\PersonalCare\RestockVitamin;
use App\Actions\PersonalCare\TakeVitaminDose;
use App\Actions\PersonalCare\UpdateProduct;
use App\Actions\PersonalCare\UpdateVitaminDose;
use App\Actions\Tasks\DeleteTask;
use App\Enums\Frequency;
use App\Enums\ScheduleLogStatus;
use App\Models\Product;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use App\Models\VitaminDose;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->vitamin = fn (?int $left = 30, ?int $remindAt = 10, string $name = 'Vitamin C 500mg') => app(CreateProduct::class)->handle($this->user, [
        'type' => 'vitamin', 'name' => $name, 'status' => 'in_use', 'units_left' => $left, 'low_stock_threshold' => $remindAt,
    ]);
    $this->dose = fn (Product $p, array $overrides = []) => app(AddVitaminDose::class)->handle($this->user, [
        'product_id' => $p->id, 'dose_time' => '08:00', 'units' => 1, 'meal_note' => 'After breakfast', ...$overrides,
    ]);
    $this->take = fn (VitaminDose $d, ?ScheduleLogStatus $s = ScheduleLogStatus::Done) => app(TakeVitaminDose::class)->handle($this->user, $d->currentRule(), $s);
    $this->left = fn (Product $p) => $p->fresh()->units_left;
    $this->reminder = fn (Product $p) => Task::where('source_type', 'product')->where('source_id', $p->id)->first();
});

// Doses

test('a dose gets its own daily schedule from today', function () {
    $dose = ($this->dose)(($this->vitamin)());

    $rule = ScheduleRule::where('schedulable_type', 'vitamin_dose')->sole();

    expect($dose->fresh())
        ->units->toBe(1)
        ->meal_note->toBe('After breakfast')
        ->and($dose->timeLabel())->toBe('8:00 AM')
        ->and($rule->schedulable_id)->toBe($dose->id)
        ->and($rule->frequency)->toBe(Frequency::Daily)
        ->and($rule->starts_on->toDateString())->toBe('2026-11-19');
});

test('invalid doses are rejected', function (Closure $input, string $field) {
    $vitamin = ($this->vitamin)();

    expect(fn () => app(AddVitaminDose::class)->handle($this->user, $input($vitamin, $this)))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(VitaminDose::count())->toBe(0);
})->with([
    'no time' => [fn ($v) => ['product_id' => $v->id, 'dose_time' => ''], 'dose_time'],
    'bad time' => [fn ($v) => ['product_id' => $v->id, 'dose_time' => '25:00'], 'dose_time'],
    '0 tablets' => [fn ($v) => ['product_id' => $v->id, 'dose_time' => '08:00', 'units' => 0], 'units'],
    'a skincare product' => [fn ($v, $t) => ['product_id' => app(CreateProduct::class)->handle($t->user, ['type' => 'skincare', 'name' => 'Toner', 'status' => 'in_use'])->id, 'dose_time' => '08:00'], 'product_id'],
    'weekly without days' => [fn ($v) => ['product_id' => $v->id, 'dose_time' => '08:00', 'frequency' => 'weekly', 'weekdays' => []], 'weekdays'],
]);

test('editing a dose\'s schedule keeps its history; removing it keeps everything', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    $vitamin = ($this->vitamin)();
    $dose = ($this->dose)($vitamin);
    ($this->take)($dose);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    app(UpdateVitaminDose::class)->handle($this->user, $dose, ['product_id' => $vitamin->id, 'dose_time' => '21:00', 'units' => 2, 'frequency' => 'weekly', 'weekdays' => [1, 3, 5]]);

    $rules = ScheduleRule::where('schedulable_type', 'vitamin_dose')->orderBy('starts_on')->get();
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->ends_on->toDateString())->toBe('2026-11-18')
        ->and(ScheduleLog::sole()->schedule_rule_id)->toBe($rules[0]->id)
        ->and($dose->fresh()->units)->toBe(2);

    app(RemoveVitaminDose::class)->handle($this->user, $dose);
    expect(VitaminDose::count())->toBe(0)->and(VitaminDose::withTrashed()->count())->toBe(1)->and(ScheduleLog::count())->toBe(1);
});

// Stock

test('taking a dose takes its tablets off; unticking puts them back', function () {
    $vitamin = ($this->vitamin)(30);
    $dose = ($this->dose)($vitamin, ['units' => 2]);

    ($this->take)($dose);
    expect(($this->left)($vitamin))->toBe(28)->and(ScheduleLog::sole()->status)->toBe(ScheduleLogStatus::Done);

    ($this->take)($dose); // ticking again changes nothing
    expect(($this->left)($vitamin))->toBe(28);

    ($this->take)($dose, null);
    expect(($this->left)($vitamin))->toBe(30)->and(ScheduleLog::count())->toBe(0);
});

test('skipping doesn\'t touch stock; done → skipped puts the tablets back', function () {
    $vitamin = ($this->vitamin)(30);
    $dose = ($this->dose)($vitamin);

    ($this->take)($dose, ScheduleLogStatus::Skipped);
    expect(($this->left)($vitamin))->toBe(30);

    ($this->take)($dose, ScheduleLogStatus::Done);
    expect(($this->left)($vitamin))->toBe(29);

    ($this->take)($dose, ScheduleLogStatus::Skipped);
    expect(($this->left)($vitamin))->toBe(30);
});

test('stock never goes below zero, and untracked stock is left alone', function () {
    $nearlyEmpty = ($this->vitamin)(1, null, 'Zinc');
    $untracked = ($this->vitamin)(null, null, 'Fish oil');

    ($this->take)(($this->dose)($nearlyEmpty, ['units' => 2]));
    ($this->take)(($this->dose)($untracked));

    expect(($this->left)($nearlyEmpty))->toBe(0)->and(($this->left)($untracked))->toBeNull();
});

test('restocking adds tablets', function () {
    $vitamin = ($this->vitamin)(5);

    app(RestockVitamin::class)->handle($this->user, $vitamin, 60);

    expect(($this->left)($vitamin))->toBe(65)
        ->and(fn () => app(RestockVitamin::class)->handle($this->user, $vitamin, 0))->toThrow(ValidationException::class);
});

// Low-stock reminder (DATABASE §3.3)

test('dropping to the level creates a "Restock" task due today, once', function () {
    $vitamin = ($this->vitamin)(11, 10);
    $dose = ($this->dose)($vitamin);

    ($this->take)($dose);

    $task = ($this->reminder)($vitamin);
    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Restock Vitamin C 500mg')
        ->and($task->due_on->toDateString())->toBe('2026-11-19')
        ->and($task->completed_at)->toBeNull();

    // Still low the next day: no new task.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    ($this->take)($dose);
    expect(Task::where('source_type', 'product')->count())->toBe(1)
        ->and(($this->reminder)($vitamin)->due_on->toDateString())->toBe('2026-11-19');
});

test('restocking above the level ticks the reminder off; the next drop reopens it', function () {
    $vitamin = ($this->vitamin)(11, 10);
    $dose = ($this->dose)($vitamin);
    ($this->take)($dose);

    app(RestockVitamin::class)->handle($this->user, $vitamin, 60);
    expect(($this->reminder)($vitamin)->completed_at)->not->toBeNull();

    // Back down to the level later.
    app(UpdateProduct::class)->handle($this->user, $vitamin->fresh(), [
        'type' => 'vitamin', 'name' => 'Vitamin C 500mg', 'status' => 'in_use', 'units_left' => 11, 'low_stock_threshold' => 10,
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00', 'Asia/Manila'));
    ($this->take)($dose);

    expect(Task::where('source_type', 'product')->count())->toBe(1)
        ->and(($this->reminder)($vitamin))
        ->completed_at->toBeNull()
        ->due_on->toDateString()->toBe('2026-11-25');
});

test('a deleted reminder stays gone until the next drop', function () {
    $vitamin = ($this->vitamin)(11, 10);
    $dose = ($this->dose)($vitamin);
    ($this->take)($dose);

    app(DeleteTask::class)->handle($this->user, ($this->reminder)($vitamin));
    ($this->take)($dose, null); // 10 → 11: above the level again
    ($this->take)($dose);       // 11 → 10: a new drop
    expect(($this->reminder)($vitamin))->not->toBeNull('crossing again makes a new one');

    app(DeleteTask::class)->handle($this->user, ($this->reminder)($vitamin));
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'Asia/Manila'));
    ($this->take)($dose); // 10 → 9: still low, no new one
    expect(($this->reminder)($vitamin))->toBeNull();
});

test('editing tablets left or the level on the product counts too', function () {
    $vitamin = ($this->vitamin)(30, 10);
    $edit = fn (?int $left, ?int $at) => app(UpdateProduct::class)->handle($this->user, $vitamin->fresh(), [
        'type' => 'vitamin', 'name' => 'Vitamin C 500mg', 'status' => 'in_use', 'units_left' => $left, 'low_stock_threshold' => $at,
    ]);

    $edit(30, 30);
    expect(($this->reminder)($vitamin))->not->toBeNull();

    $edit(30, 10);
    expect(($this->reminder)($vitamin)->completed_at)->not->toBeNull();
});

test('a vitamin added already at its level gets a reminder right away', function () {
    $vitamin = ($this->vitamin)(5, 10);

    expect(($this->reminder)($vitamin))->not->toBeNull();
});

test('a dose of a vitamin can\'t be logged for a day it isn\'t scheduled', function () {
    $dose = ($this->dose)(($this->vitamin)(), ['frequency' => 'weekly', 'weekdays' => [1]]); // Mondays

    expect(fn () => ($this->take)($dose))->toThrow(ValidationException::class);
    expect(ScheduleLog::count())->toBe(0);
});
