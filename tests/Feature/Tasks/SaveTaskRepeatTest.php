<?php

use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\SaveTaskRepeat;
use App\Enums\Frequency;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->task = Task::factory()->dueOn('2026-11-25', '08:00')->create(['title' => 'Water the plants']);
    $this->save = fn (?array $repeat, array $fields = []) => app(SaveTaskRepeat::class)->handle($this->user, $this->task, [
        'title' => 'Water the plants', 'due_time' => '08:00', ...$fields,
    ], $repeat);
    $this->rules = fn () => ScheduleRule::where('schedulable_id', $this->task->id)->orderBy('starts_on')->get();
});

test('making a task repeat starts its schedule today and clears its own date', function () {
    ($this->save)(['frequency' => 'weekly', 'weekdays' => [1, 4]], ['title' => 'Water the plants (renamed)']);

    $rule = ($this->rules)()->sole();
    $task = $this->task->fresh();

    expect($rule->frequency)->toBe(Frequency::Weekly)
        ->and($rule->starts_on->toDateString())->toBe('2026-11-19')
        ->and($task->title)->toBe('Water the plants (renamed)')
        ->and($task->due_on)->toBeNull()
        ->and(substr((string) $task->due_time, 0, 5))->toBe('08:00')
        ->and($task->isRecurring())->toBeTrue();
});

test('changing the repeat keeps the old version for history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    ($this->save)(['frequency' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 09:00:00', 'Asia/Manila'));
    app(MarkOccurrence::class)->handle($this->user, ($this->rules)()->sole(), '2026-11-12', ScheduleLogStatus::Done);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    ($this->save)(['frequency' => 'every_n_days', 'interval' => 3]);

    [$old, $new] = ($this->rules)()->all();
    expect($old->ends_on->toDateString())->toBe('2026-11-18')
        ->and($new->starts_on->toDateString())->toBe('2026-11-19')
        ->and($new->interval)->toBe(3)
        ->and(ScheduleLog::sole()->schedule_rule_id)->toBe($old->id);
});

test('saving without changing the repeat changes nothing about it', function () {
    ($this->save)(['frequency' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00', 'Asia/Manila'));

    ($this->save)(['frequency' => 'daily'], ['title' => 'New title']);

    expect(($this->rules)())->toHaveCount(1)->and($this->task->fresh()->title)->toBe('New title');
});

test('a repeat can be paused and resumed from the same form', function () {
    ($this->save)(['frequency' => 'daily', 'paused' => true]);
    expect(($this->rules)()->sole()->isPaused())->toBeTrue();

    ($this->save)(['frequency' => 'daily', 'paused' => false]);
    expect(($this->rules)()->sole()->isPaused())->toBeFalse();
});

test('stopping a repeat that already started ends it yesterday; the task is one-off again', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    ($this->save)(['frequency' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    ($this->save)(null, ['due_on' => '2026-11-30']);

    expect(($this->rules)()->sole()->ends_on->toDateString())->toBe('2026-11-18')
        ->and($this->task->fresh()->isRecurring())->toBeFalse()
        ->and($this->task->fresh()->due_on->toDateString())->toBe('2026-11-30');
});

test('stopping a repeat that starts today removes it', function () {
    ($this->save)(['frequency' => 'daily']);

    ($this->save)(null);

    expect(($this->rules)())->toHaveCount(0);
});

test('a bad repeat changes nothing, not even the title', function (array $repeat, string $field) {
    expect(fn () => ($this->save)($repeat, ['title' => 'Should not be saved']))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect($this->task->fresh()->title)->toBe('Water the plants')
        ->and(($this->rules)())->toHaveCount(0);
})->with([
    'no weekdays' => [['frequency' => 'weekly', 'weekdays' => []], 'weekdays'],
    'interval 1' => [['frequency' => 'every_n_days', 'interval' => 1], 'interval'],
    'day 32' => [['frequency' => 'monthly', 'day_of_month' => 32], 'day_of_month'],
    'until before today' => [['frequency' => 'daily', 'ends_on' => '2026-11-01'], 'ends_on'],
]);

test('a debt\'s task can\'t repeat', function () {
    $debtTask = Task::factory()->create(['source_type' => 'debt', 'source_id' => 1]);

    expect(fn () => app(SaveTaskRepeat::class)->handle($this->user, $debtTask, ['title' => 'x'], ['frequency' => 'daily']))
        ->toThrow(ValidationException::class);

    expect(ScheduleRule::count())->toBe(0);
});

test('a repeating task can\'t be completed for good', function () {
    ($this->save)(['frequency' => 'daily']);

    expect(fn () => app(CompleteTask::class)->handle($this->user, $this->task->fresh()))
        ->toThrow(AuthorizationException::class, 'This task repeats. Tick today\'s one instead.');
});

test('another user\'s task returns 404', function () {
    $intruder = User::factory()->create();
    $this->actingAs($intruder);

    expect(fn () => app(SaveTaskRepeat::class)->handle($intruder, $this->task, ['title' => 'Hacked'], ['frequency' => 'daily']))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });

    $this->actingAs($this->user);
    expect($this->task->fresh()->title)->toBe('Water the plants')->and(ScheduleRule::count())->toBe(0);
});
