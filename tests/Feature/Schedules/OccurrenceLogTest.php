<?php

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\PauseSchedule;
use App\Actions\Schedules\UnmarkOccurrence;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026, 9:00 am in Manila (01:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    // Mondays, Wednesdays and Fridays.
    $this->rule = app(CreateSchedule::class)->handle($this->user, Task::factory()->create(), [
        'frequency' => 'weekly', 'weekdays' => [1, 3, 5], 'starts_on' => '2026-11-01',
    ]);
    $this->mark = fn (string $date, ScheduleLogStatus $status = ScheduleLogStatus::Done) => app(MarkOccurrence::class)->handle($this->user, $this->rule, $date, $status);
});

test('an occurrence is marked done, with the moment stored in UTC', function () {
    $log = ($this->mark)('2026-11-18');

    $fresh = ScheduleLog::findOrFail($log->id);

    expect($fresh->schedule_rule_id)->toBe($this->rule->id)
        ->and($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->occurs_on->toDateString())->toBe('2026-11-18')
        ->and($fresh->status)->toBe(ScheduleLogStatus::Done)
        ->and($fresh->getRawOriginal('logged_at'))->toBe('2026-11-19 01:00:00');
});

test('an occurrence can be skipped, and marking again just changes its status', function () {
    ($this->mark)('2026-11-18', ScheduleLogStatus::Skipped);
    ($this->mark)('2026-11-18', ScheduleLogStatus::Done);

    expect(ScheduleLog::sole()->status)->toBe(ScheduleLogStatus::Done);
});

test('unmarking deletes the log, and unmarking twice is harmless', function () {
    ($this->mark)('2026-11-18');

    app(UnmarkOccurrence::class)->handle($this->user, $this->rule, '2026-11-18');
    app(UnmarkOccurrence::class)->handle($this->user, $this->rule, '2026-11-18');

    expect(ScheduleLog::count())->toBe(0);
});

test('a date the rule doesn\'t fall on is refused', function () {
    // Nov 19 is a Thursday.
    expect(fn () => ($this->mark)('2026-11-19'))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors())->toHaveKey('date');
        });

    expect(ScheduleLog::count())->toBe(0);
});

test('a future date is refused; "today" is the user\'s today', function () {
    // Nov 20 (Friday) is tomorrow in Manila.
    expect(fn () => ($this->mark)('2026-11-20'))->toThrow(ValidationException::class);

    // 00:30 Nov 20 in Manila is still Nov 19 in UTC, but already "today" for the user.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:30:00', 'Asia/Manila'));
    ($this->mark)('2026-11-20');

    expect(ScheduleLog::sole()->occurs_on->toDateString())->toBe('2026-11-20');
});

test('a paused rule\'s dates can\'t be marked', function () {
    app(PauseSchedule::class)->handle($this->user, $this->rule);

    expect(fn () => ($this->mark)('2026-11-18'))->toThrow(ValidationException::class);
});

test('bad dates are refused', function (string $date) {
    ($this->mark)($date);
})->with(['2026-02-30', 'yesterday', ''])->throws(ValidationException::class);

test('each rule and date has one log at most (database rule too)', function () {
    ($this->mark)('2026-11-18');

    $duplicate = new ScheduleLog;
    $duplicate->user_id = $this->user->id;
    $duplicate->schedule_rule_id = $this->rule->id;
    $duplicate->setAttribute('occurs_on', '2026-11-18');
    $duplicate->status = ScheduleLogStatus::Skipped;
    $duplicate->logged_at = now();

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});
