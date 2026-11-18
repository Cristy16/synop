<?php

use App\Enums\TaskFilter;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskList;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    $this->list = app(TaskList::class);

    // Nov 19, 9:00 am in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
});

function titles(TaskList $list, User $user, TaskFilter $filter): array
{
    return $list->for($user, $filter)->pluck('title')->all();
}

test('each task lands in exactly one tab', function () {
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'today']);
    Task::factory()->dueOn('2026-11-20')->create(['title' => 'tomorrow']);
    Task::factory()->dueOn('2026-11-18')->create(['title' => 'yesterday']);
    Task::factory()->create(['title' => 'no date']);
    Task::factory()->dueOn('2026-11-18')->completed()->create(['title' => 'done']);

    expect(titles($this->list, $this->user, TaskFilter::Today))->toBe(['today'])
        ->and(titles($this->list, $this->user, TaskFilter::Upcoming))->toBe(['tomorrow'])
        ->and(titles($this->list, $this->user, TaskFilter::Overdue))->toBe(['yesterday'])
        ->and(titles($this->list, $this->user, TaskFilter::NoDate))->toBe(['no date'])
        ->and(titles($this->list, $this->user, TaskFilter::Done))->toBe(['done'])
        ->and($this->list->counts($this->user))->toBe([
            'today' => 1, 'upcoming' => 1, 'overdue' => 1, 'no-date' => 1, 'done' => 1,
        ]);
});

test('today follows the user\'s timezone, not UTC', function () {
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'due Nov 19']);

    // 11:30 pm Nov 19 in Manila (already 15:30 UTC): still today.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 23:30:00', 'Asia/Manila'));
    expect(titles($this->list, $this->user, TaskFilter::Today))->toBe(['due Nov 19']);

    // 12:30 am Nov 20 in Manila (still Nov 19 in UTC): overdue.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 00:30:00', 'Asia/Manila'));
    expect(titles($this->list, $this->user, TaskFilter::Today))->toBe([])
        ->and(titles($this->list, $this->user, TaskFilter::Overdue))->toBe(['due Nov 19']);
});

test('today is ordered by time (untimed last), then high priority first', function () {
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'untimed normal']);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'untimed high', 'priority' => TaskPriority::High]);
    Task::factory()->dueOn('2026-11-19', '14:00')->create(['title' => '2 pm']);
    Task::factory()->dueOn('2026-11-19', '08:00')->create(['title' => '8 am']);

    expect(titles($this->list, $this->user, TaskFilter::Today))
        ->toBe(['8 am', '2 pm', 'untimed high', 'untimed normal']);
});

test('upcoming and overdue are ordered by date, soonest / oldest first', function () {
    Task::factory()->dueOn('2026-11-25')->create(['title' => 'Nov 25']);
    Task::factory()->dueOn('2026-11-20', '15:00')->create(['title' => 'Nov 20 3 pm']);
    Task::factory()->dueOn('2026-11-20', '09:00')->create(['title' => 'Nov 20 9 am']);
    Task::factory()->dueOn('2026-11-10')->create(['title' => 'Nov 10']);
    Task::factory()->dueOn('2026-11-01')->create(['title' => 'Nov 1']);

    expect(titles($this->list, $this->user, TaskFilter::Upcoming))->toBe(['Nov 20 9 am', 'Nov 20 3 pm', 'Nov 25'])
        ->and(titles($this->list, $this->user, TaskFilter::Overdue))->toBe(['Nov 1', 'Nov 10']);
});

test('no date is ordered high priority first, then newest', function () {
    Task::factory()->create(['title' => 'old low', 'priority' => TaskPriority::Low]);
    Task::factory()->create(['title' => 'old normal']);
    Task::factory()->create(['title' => 'new normal']);
    Task::factory()->create(['title' => 'high', 'priority' => TaskPriority::High]);

    expect(titles($this->list, $this->user, TaskFilter::NoDate))
        ->toBe(['high', 'new normal', 'old normal', 'old low']);
});

test('done is ordered by most recently completed', function () {
    Task::factory()->create(['title' => 'first', 'completed_at' => now()->subHour()]);
    Task::factory()->create(['title' => 'latest', 'completed_at' => now()]);

    expect(titles($this->list, $this->user, TaskFilter::Done))->toBe(['latest', 'first']);
});

test('only the user\'s own tasks are listed and counted', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'theirs']);
    $this->actingAs($this->user);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'mine']);

    expect(titles($this->list, $this->user, TaskFilter::Today))->toBe(['mine'])
        ->and($this->list->counts($this->user)['today'])->toBe(1);
});

test('quick-add uses today only on the Today tab', function () {
    expect(TaskFilter::Today->quickAddDueOn('2026-11-19'))->toBe('2026-11-19');

    foreach ([TaskFilter::Upcoming, TaskFilter::Overdue, TaskFilter::NoDate, TaskFilter::Done] as $filter) {
        expect($filter->quickAddDueOn('2026-11-19'))->toBeNull()
            ->and($filter->quickAddTarget())->toBe(TaskFilter::NoDate);
    }
});
