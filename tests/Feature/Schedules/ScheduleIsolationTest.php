<?php

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\PauseSchedule;
use App\Actions\Schedules\ResumeSchedule;
use App\Actions\Schedules\UnmarkOccurrence;
use App\Actions\Schedules\UpdateSchedule;
use App\Enums\ScheduleLogStatus;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
});

function dailyRuleFor(User $owner): ScheduleRule
{
    return app(CreateSchedule::class)->handle($owner, Task::factory()->create(), ['frequency' => 'daily', 'starts_on' => '2026-11-01']);
}

test('a schedule rule is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => dailyRuleFor($owner));
});

test('a schedule log is invisible to another user', function () {
    assertInvisibleToOtherUser(
        fn (User $owner) => app(MarkOccurrence::class)->handle($owner, dailyRuleFor($owner), '2026-11-18', ScheduleLogStatus::Done)
    );
});

test('another user gets a 404 from every schedule action', function (Closure $call) {
    [$owner, $intruder] = User::factory()->count(2)->create();

    $this->actingAs($owner);
    $rule = dailyRuleFor($owner);
    app(MarkOccurrence::class)->handle($owner, $rule, '2026-11-17', ScheduleLogStatus::Done);
    $snapshot = [$rule->fresh()->getAttributes(), ScheduleLog::count(), ScheduleRule::count()];

    $this->actingAs($intruder);

    expect(fn () => $call($intruder, $rule))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });

    $this->actingAs($owner);
    expect([$rule->fresh()->getAttributes(), ScheduleLog::count(), ScheduleRule::count()])->toEqual($snapshot);
})->with([
    'update' => fn (User $u, ScheduleRule $r) => app(UpdateSchedule::class)->handle($u, $r, ['frequency' => 'weekly', 'weekdays' => [1]]),
    'pause' => fn (User $u, ScheduleRule $r) => app(PauseSchedule::class)->handle($u, $r),
    'resume' => fn (User $u, ScheduleRule $r) => app(ResumeSchedule::class)->handle($u, $r),
    'mark' => fn (User $u, ScheduleRule $r) => app(MarkOccurrence::class)->handle($u, $r, '2026-11-18', ScheduleLogStatus::Done),
    'unmark' => fn (User $u, ScheduleRule $r) => app(UnmarkOccurrence::class)->handle($u, $r, '2026-11-17'),
]);

test('a schedule can\'t be attached to another user\'s item', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirTask = Task::factory()->create();

    $me = User::factory()->create();
    $this->actingAs($me);

    expect(fn () => app(CreateSchedule::class)->handle($me, $theirTask, ['frequency' => 'daily', 'starts_on' => '2026-11-19']))
        ->toThrow(LogicException::class);

    expect(ScheduleRule::forUser($me)->count())->toBe(0)
        ->and(ScheduleRule::forUser($other)->count())->toBe(0);
});
