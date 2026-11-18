<?php

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\PauseSchedule;
use App\Enums\ScheduleLogStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\ScheduledItems;
use App\Support\ScheduledItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->items = app(ScheduledItems::class);
    $this->repeat = fn (string $title, array $rule) => app(CreateSchedule::class)->handle(
        $this->user, Task::factory()->create(['title' => $title]), ['starts_on' => '2026-11-01', ...$rule]
    );
    $this->titlesOn = fn (string $date) => $this->items->on($this->user, $date, 'task')->map(fn (ScheduledItem $i) => $i->item->title)->all();
});

test('items appear on the days their rule falls on', function () {
    ($this->repeat)('Daily', ['frequency' => 'daily']);
    ($this->repeat)('Thursdays', ['frequency' => 'weekly', 'weekdays' => [4]]);
    ($this->repeat)('Mondays', ['frequency' => 'weekly', 'weekdays' => [1]]);
    ($this->repeat)('On the 19th', ['frequency' => 'monthly', 'day_of_month' => 19]);

    expect(($this->titlesOn)('2026-11-19'))->toBe(['Daily', 'Thursdays', 'On the 19th'])
        ->and(($this->titlesOn)('2026-11-23'))->toBe(['Daily', 'Mondays']);
});

test('each item carries that day\'s done or skipped state', function () {
    $done = ($this->repeat)('Done one', ['frequency' => 'daily']);
    $skipped = ($this->repeat)('Skipped one', ['frequency' => 'daily']);
    ($this->repeat)('Open one', ['frequency' => 'daily']);
    app(MarkOccurrence::class)->handle($this->user, $done, '2026-11-19', ScheduleLogStatus::Done);
    app(MarkOccurrence::class)->handle($this->user, $skipped, '2026-11-19', ScheduleLogStatus::Skipped);
    app(MarkOccurrence::class)->handle($this->user, $skipped, '2026-11-18', ScheduleLogStatus::Done); // another day

    $states = $this->items->on($this->user, '2026-11-19', 'task')
        ->mapWithKeys(fn (ScheduledItem $i) => [$i->item->title => $i->status()?->value])
        ->all();

    expect($states)->toBe(['Done one' => 'done', 'Skipped one' => 'skipped', 'Open one' => null]);
});

test('paused, ended and not-yet-started rules give nothing', function () {
    $paused = ($this->repeat)('Paused', ['frequency' => 'daily']);
    app(PauseSchedule::class)->handle($this->user, $paused);
    ($this->repeat)('Ended', ['frequency' => 'daily', 'ends_on' => '2026-11-18']);
    ($this->repeat)('Later', ['frequency' => 'daily', 'starts_on' => '2026-11-20']);

    expect(($this->titlesOn)('2026-11-19'))->toBe([]);
});

test('items whose task is gone are skipped', function () {
    $rule = ($this->repeat)('Soon gone', ['frequency' => 'daily']);
    // Simulate an orphan: the task row disappears without its cleanup event.
    Task::withoutEvents(fn () => Task::whereKey($rule->schedulable_id)->delete());

    expect(($this->titlesOn)('2026-11-19'))->toBe([]);
});

test('another user\'s items never appear', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    app(CreateSchedule::class)->handle($other, Task::factory()->create(['title' => 'Theirs']), ['frequency' => 'daily', 'starts_on' => '2026-11-01']);
    $this->actingAs($this->user);
    ($this->repeat)('Mine', ['frequency' => 'daily']);

    expect(($this->titlesOn)('2026-11-19'))->toBe(['Mine']);
});

test('the number of queries doesn\'t grow with the number of items', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->items->on($this->user, '2026-11-19', 'task');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    ($this->repeat)('One', ['frequency' => 'daily']);
    $withOne = $count();

    foreach (range(2, 20) as $n) {
        ($this->repeat)("Item {$n}", ['frequency' => 'daily']);
    }

    expect($count())->toBe($withOne)->and($withOne)->toBeLessThanOrEqual(3);
});
