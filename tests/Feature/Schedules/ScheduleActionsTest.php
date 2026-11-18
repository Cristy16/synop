<?php

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\PauseSchedule;
use App\Actions\Schedules\ResumeSchedule;
use App\Actions\Schedules\UpdateSchedule;
use App\Actions\Tasks\DeleteTask;
use App\Enums\Frequency;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use App\Services\ScheduleEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\OwnedThing;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026, 9:00 am in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->task = Task::factory()->create(['title' => 'Water the plants']);
    $this->create = fn (array $input, $schedulable = null) => app(CreateSchedule::class)->handle($this->user, $schedulable ?? $this->task, $input);
    $this->update = fn (ScheduleRule $rule, array $input) => app(UpdateSchedule::class)->handle($this->user, $rule, $input);
    $this->rulesOf = fn () => ScheduleRule::where('schedulable_type', 'task')->where('schedulable_id', $this->task->id)->orderBy('starts_on')->get();
});

// Create

test('a schedule is created for each frequency', function (array $input, array $expected) {
    $rule = ($this->create)(['starts_on' => '2026-11-19', ...$input]);

    $fresh = ScheduleRule::findOrFail($rule->id);

    expect($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->getRawOriginal('schedulable_type'))->toBe('task')
        ->and($fresh->schedulable_id)->toBe($this->task->id)
        ->and($fresh->schedulable?->is($this->task))->toBeTrue()
        ->and($fresh->starts_on->toDateString())->toBe('2026-11-19')
        ->and($fresh->only(['interval', 'weekdays', 'day_of_month']))->toBe($expected);
})->with([
    'daily' => [['frequency' => 'daily'], ['interval' => null, 'weekdays' => null, 'day_of_month' => null]],
    'every 3 days' => [['frequency' => 'every_n_days', 'interval' => 3], ['interval' => 3, 'weekdays' => null, 'day_of_month' => null]],
    'weekly, sorted and unique' => [['frequency' => 'weekly', 'weekdays' => [5, 1, 3, 1]], ['interval' => null, 'weekdays' => [1, 3, 5], 'day_of_month' => null]],
    'monthly' => [['frequency' => 'monthly', 'day_of_month' => 31], ['interval' => null, 'weekdays' => null, 'day_of_month' => 31]],
]);

test('invalid schedules are rejected', function (array $input, string $field) {
    expect(fn () => ($this->create)(['starts_on' => '2026-11-19', ...$input]))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(ScheduleRule::count())->toBe(0);
})->with([
    'unknown frequency' => [['frequency' => 'yearly'], 'frequency'],
    'every N without interval' => [['frequency' => 'every_n_days'], 'interval'],
    'interval 1 (that is daily)' => [['frequency' => 'every_n_days', 'interval' => 1], 'interval'],
    'interval over 365' => [['frequency' => 'every_n_days', 'interval' => 366], 'interval'],
    'weekly without days' => [['frequency' => 'weekly', 'weekdays' => []], 'weekdays'],
    'weekday 0' => [['frequency' => 'weekly', 'weekdays' => [0]], 'weekdays.0'],
    'weekday 8' => [['frequency' => 'weekly', 'weekdays' => [1, 8]], 'weekdays.1'],
    'monthly day 0' => [['frequency' => 'monthly', 'day_of_month' => 0], 'day_of_month'],
    'monthly day 32' => [['frequency' => 'monthly', 'day_of_month' => 32], 'day_of_month'],
    'daily with an interval' => [['frequency' => 'daily', 'interval' => 3], 'interval'],
    'weekly with a day of month' => [['frequency' => 'weekly', 'weekdays' => [1], 'day_of_month' => 5], 'day_of_month'],
    'monthly with weekdays' => [['frequency' => 'monthly', 'day_of_month' => 5, 'weekdays' => [1]], 'weekdays'],
    'missing start' => [['frequency' => 'daily', 'starts_on' => null], 'starts_on'],
    'end before start' => [['frequency' => 'daily', 'ends_on' => '2026-11-18'], 'ends_on'],
    'bad date' => [['frequency' => 'daily', 'starts_on' => '19/11/2026'], 'starts_on'],
]);

test('rules for the same item can\'t overlap', function () {
    ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);

    expect(fn () => ($this->create)(['frequency' => 'weekly', 'weekdays' => [1], 'starts_on' => '2026-11-30']))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors())->toHaveKey('starts_on');
        });

    // Right after the end is fine.
    ($this->create)(['frequency' => 'weekly', 'weekdays' => [1], 'starts_on' => '2026-12-01']);
    expect(ScheduleRule::count())->toBe(2);
});

test('another item\'s rules don\'t count as overlapping', function () {
    $other = Task::factory()->create();
    ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01'], $other);

    ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    expect(ScheduleRule::count())->toBe(2);
});

test('a schedule can\'t be attached to a model missing from the morph map', function () {
    OwnedThing::createTable();

    ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-19'], OwnedThing::create(['name' => 'x']));
})->throws(ClassMorphViolationException::class);

// Update: in place

test('a rule that hasn\'t started yet is edited in place', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-25']);

    $updated = ($this->update)($rule, ['frequency' => 'weekly', 'weekdays' => [2, 6], 'starts_on' => '2026-11-24']);

    expect($updated->id)->toBe($rule->id)
        ->and(ScheduleRule::count())->toBe(1)
        ->and($updated->fresh()->frequency)->toBe(Frequency::Weekly)
        ->and($updated->fresh()->starts_on->toDateString())->toBe('2026-11-24');
});

test('a rule starting today is edited in place', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-19']);

    $updated = ($this->update)($rule, ['frequency' => 'monthly', 'day_of_month' => 19]);

    expect($updated->id)->toBe($rule->id)->and(ScheduleRule::count())->toBe(1);
});

test('only moving the end date (to yesterday or later) is done in place', function (?string $endsOn) {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);

    $updated = ($this->update)($rule, ['frequency' => 'daily', 'ends_on' => $endsOn]);

    expect($updated->id)->toBe($rule->id)
        ->and(ScheduleRule::count())->toBe(1)
        ->and($updated->fresh()->ends_on?->toDateString())->toBe($endsOn);
})->with([
    'extend' => ['2026-12-31'],
    'remove the end' => [null],
    'shorten' => ['2026-11-25'],
    'stop from today (end yesterday)' => ['2026-11-18'],
]);

test('the end date can\'t be moved before yesterday: that would change the past', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    expect(fn () => ($this->update)($rule, ['frequency' => 'daily', 'ends_on' => '2026-11-17']))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors())->toHaveKey('ends_on');
        });
});

// Update: versioning (D-013)

test('changing a rule that started before today keeps history: old ends yesterday, new starts today', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);
    app(MarkOccurrence::class)->handle($this->user, $rule, '2026-11-10', ScheduleLogStatus::Done);

    $new = ($this->update)($rule, ['frequency' => 'weekly', 'weekdays' => [1, 3, 5]]);

    [$old, $current] = ($this->rulesOf)()->all();

    expect($new->id)->toBe($current->id)->not->toBe($rule->id)
        ->and($old->ends_on->toDateString())->toBe('2026-11-18')
        ->and($old->frequency)->toBe(Frequency::Daily)
        ->and($current->starts_on->toDateString())->toBe('2026-11-19')
        ->and($current->frequency)->toBe(Frequency::Weekly)
        ->and($current->weekdays)->toBe([1, 3, 5])
        ->and(ScheduleLog::sole()->schedule_rule_id)->toBe($old->id, 'old logs stay on the old rule');
});

test('a new version keeps the end date asked for', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    $new = ($this->update)($rule, ['frequency' => 'monthly', 'day_of_month' => 1, 'ends_on' => '2027-06-30']);

    expect($new->fresh()->ends_on->toDateString())->toBe('2027-06-30');
});

test('every N days with the same interval keeps its cycle: changing the end date is done in place', function () {
    // Every 3 days from Nov 2: Nov 2, 5, 8, 11, 14, 17, 20, 23…
    $rule = ($this->create)(['frequency' => 'every_n_days', 'interval' => 3, 'starts_on' => '2026-11-02']);

    // A started rule's start date can't be moved (it would rewrite the past), so it is ignored here.
    $new = ($this->update)($rule, ['frequency' => 'every_n_days', 'interval' => 3, 'ends_on' => '2026-12-31', 'starts_on' => '2026-11-10']);

    expect($new->id)->toBe($rule->id)
        ->and(ScheduleRule::count())->toBe(1)
        ->and($new->fresh()->starts_on->toDateString())->toBe('2026-11-02')
        ->and(app(ScheduleEngine::class)->nextOnOrAfter($new->fresh(), '2026-11-19'))->toBe('2026-11-20');
});

test('changing the interval starts the new cycle today', function () {
    $rule = ($this->create)(['frequency' => 'every_n_days', 'interval' => 3, 'starts_on' => '2026-11-02']);

    $new = ($this->update)($rule, ['frequency' => 'every_n_days', 'interval' => 2]);

    expect($new->id)->not->toBe($rule->id)
        ->and($new->fresh()->starts_on->toDateString())->toBe('2026-11-19');
});

test('switching to every N days from another frequency starts the cycle today', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    $new = ($this->update)($rule, ['frequency' => 'every_n_days', 'interval' => 3]);

    expect($new->fresh()->starts_on->toDateString())->toBe('2026-11-19');
});

test('a paused rule stays paused in its new version', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);
    app(PauseSchedule::class)->handle($this->user, $rule);

    $new = ($this->update)($rule, ['frequency' => 'weekly', 'weekdays' => [1]]);

    expect($new->fresh()->paused_at)->not->toBeNull();
});

test('a rule that already ended can\'t be edited', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-10']);

    expect(fn () => ($this->update)($rule, ['frequency' => 'daily', 'ends_on' => '2026-12-31']))
        ->toThrow(ValidationException::class);
});

test('a version can\'t end before it starts', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    expect(fn () => ($this->update)($rule, ['frequency' => 'weekly', 'weekdays' => [1], 'ends_on' => '2026-11-18']))
        ->toThrow(ValidationException::class);

    expect(ScheduleRule::count())->toBe(1);
});

test('invalid input when versioning changes nothing', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    expect(fn () => ($this->update)($rule, ['frequency' => 'weekly', 'weekdays' => []]))->toThrow(ValidationException::class);

    expect(ScheduleRule::count())->toBe(1)->and($rule->fresh()->ends_on)->toBeNull();
});

// Pause / resume (in place)

test('pausing and resuming change the same rule', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);

    app(PauseSchedule::class)->handle($this->user, $rule);
    expect($rule->fresh()->paused_at)->not->toBeNull();

    app(ResumeSchedule::class)->handle($this->user, $rule);
    expect($rule->fresh()->paused_at)->toBeNull()->and(ScheduleRule::count())->toBe(1);
});

// Cleanup (DATABASE §1.5)

test('deleting a task removes its schedule rules and their logs', function () {
    $rule = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01']);
    app(MarkOccurrence::class)->handle($this->user, $rule, '2026-11-18', ScheduleLogStatus::Done);
    $other = ($this->create)(['frequency' => 'daily', 'starts_on' => '2026-11-01'], Task::factory()->create());

    app(DeleteTask::class)->handle($this->user, $this->task);

    expect(ScheduleRule::pluck('id')->all())->toBe([$other->id])
        ->and(ScheduleLog::count())->toBe(0);
});
