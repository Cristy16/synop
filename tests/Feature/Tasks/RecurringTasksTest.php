<?php

use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Tasks\SaveTaskRepeat;
use App\Enums\ScheduleLogStatus;
use App\Enums\TaskFilter;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskList;
use App\Support\TaskRow;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026, 9:00 am in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->repeating = function (string $title, array $repeat, ?string $time = null): Task {
        $task = Task::factory()->create(['title' => $title]);
        app(SaveTaskRepeat::class)->handle($this->user, $task, ['title' => $title, 'due_time' => $time], $repeat);

        return $task->fresh();
    };
    $this->list = app(TaskList::class);
    $this->titles = fn (TaskFilter $f) => array_map(fn (TaskRow $r) => $r->task->title, $this->list->rows($this->user, $f));
});

// Which tabs

test('a repeating task shows on Today on its days, on Upcoming with its next date, and nowhere else', function () {
    ($this->repeating)('Water the plants', ['frequency' => 'daily']);
    ($this->repeating)('Face mask', ['frequency' => 'weekly', 'weekdays' => [6]]); // Saturdays
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'One-off today']);
    Task::factory()->dueOn('2026-11-20')->create(['title' => 'One-off tomorrow']);

    $upcoming = collect($this->list->rows($this->user, TaskFilter::Upcoming))->mapWithKeys(fn (TaskRow $r) => [$r->task->title => $r->date])->all();

    expect(($this->titles)(TaskFilter::Today))->toEqualCanonicalizing(['Water the plants', 'One-off today'])
        ->and($upcoming)->toBe([
            // Same date and no time: oldest first.
            'Water the plants' => '2026-11-20',
            'One-off tomorrow' => '2026-11-20',
            'Face mask' => '2026-11-21',
        ])
        ->and(($this->titles)(TaskFilter::Overdue))->toBe([])
        ->and(($this->titles)(TaskFilter::NoDate))->toBe([])
        ->and(($this->titles)(TaskFilter::Done))->toBe([]);
});

test('a paused repeating task is listed last on Upcoming, with no date', function () {
    ($this->repeating)('Paused one', ['frequency' => 'daily', 'paused' => true]);
    Task::factory()->dueOn('2026-12-31')->create(['title' => 'Far one-off']);

    $rows = $this->list->rows($this->user, TaskFilter::Upcoming);

    expect(array_map(fn (TaskRow $r) => [$r->task->title, $r->date], $rows))->toBe([
        ['Far one-off', '2026-12-31'],
        ['Paused one', null],
    ])->and(($this->titles)(TaskFilter::Today))->toBe([]);
});

test('tab counts include repeating tasks not yet done today', function () {
    $plants = ($this->repeating)('Water the plants', ['frequency' => 'daily']);
    ($this->repeating)('Stretch', ['frequency' => 'daily']);

    expect($this->list->counts($this->user)['today'])->toBe(2)
        ->and($this->list->counts($this->user)['upcoming'])->toBe(2);

    app(MarkOccurrence::class)->handle($this->user, $plants->currentRule(), '2026-11-19', ScheduleLogStatus::Done);

    expect($this->list->counts($this->user)['today'])->toBe(1);
});

test('once a repeat is stopped, the task is a normal one-off again', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Asia/Manila'));
    $task = ($this->repeating)('Water the plants', ['frequency' => 'daily']);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
    app(SaveTaskRepeat::class)->handle($this->user, $task, ['title' => 'Water the plants'], null);

    expect(($this->titles)(TaskFilter::NoDate))->toBe(['Water the plants'])
        ->and(($this->titles)(TaskFilter::Today))->toBe([]);
});

// Ticking on the Tasks page

test('ticking a repeating task on the Tasks page marks today done, and unticking clears it', function () {
    $task = ($this->repeating)('Water the plants', ['frequency' => 'daily']);

    $page = Livewire::test('pages::tasks.index')->call('toggle', $task->id);
    expect(ScheduleLog::sole())
        ->status->toBe(ScheduleLogStatus::Done)
        ->occurs_on->toDateString()->toBe('2026-11-19')
        ->and($task->fresh()->isCompleted())->toBeFalse('a repeating task is never completed for good');

    $page->call('toggle', $task->id);
    expect(ScheduleLog::count())->toBe(0);
});

test('skip and undo on the Tasks page', function () {
    $task = ($this->repeating)('Water the plants', ['frequency' => 'daily']);

    $page = Livewire::test('pages::tasks.index')
        ->assertSee('↻ Every day')
        ->call('skip', $task->id)
        ->assertSee('Skipped')
        ->assertSee('Undo');
    expect(ScheduleLog::sole()->status)->toBe(ScheduleLogStatus::Skipped);

    $page->call('unskip', $task->id);
    expect(ScheduleLog::count())->toBe(0);
});

test('the Upcoming tab shows the next date and no checkbox for a repeating task', function () {
    ($this->repeating)('Face mask', ['frequency' => 'every_n_days', 'interval' => 3]);

    Livewire::withQueryParams(['filter' => 'upcoming'])
        ->test('pages::tasks.index')
        ->assertSee('↻ Every 3 days')
        ->assertSee('Sun, Nov 22')
        ->assertDontSee('Complete Face mask');
});

// The edit window

test('a task can be made to repeat from its edit window', function () {
    $task = Task::factory()->dueOn('2026-11-25')->create(['title' => 'Pay rent']);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertSee('Repeat')
        ->set('repeat_frequency', 'monthly')
        ->set('repeat_day', '30')
        ->call('save')
        ->assertHasNoErrors();

    expect(ScheduleRule::sole())->day_of_month->toBe(30)
        ->and($task->fresh()->due_on)->toBeNull();
});

test('the edit window loads the current repeat, and shows repeat errors on its own fields', function () {
    $task = ($this->repeating)('Gym', ['frequency' => 'weekly', 'weekdays' => [1, 3, 5]]);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertSet('repeat_frequency', 'weekly')
        ->assertSet('repeat_weekdays', ['1', '3', '5'])
        ->set('repeat_weekdays', [])
        ->call('save')
        ->assertHasErrors(['repeat_weekdays']);
});

test('a debt\'s task has no Repeat section', function () {
    $task = Task::factory()->create(['source_type' => 'debt', 'source_id' => 1]);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertDontSee('data-test="repeat-section"', false);
});

// Today

test('Today lists today\'s repeating tasks with one-offs, and counts done and skipped right', function () {
    $plants = ($this->repeating)('Water the plants', ['frequency' => 'daily'], '07:00');
    $stretch = ($this->repeating)('Stretch', ['frequency' => 'daily']);
    ($this->repeating)('Mondays only', ['frequency' => 'weekly', 'weekdays' => [1]]);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'One-off today']);

    $section = Livewire::test('today.tasks')
        ->assertSee(['One-off today', 'Water the plants', '7:00 AM', 'Stretch'])
        ->assertDontSee('Mondays only')
        ->assertSee('0 of 3 done');

    $section->call('toggle', $plants->id)->assertSee('1 of 3 done');
    $section->call('skip', $stretch->id)->assertSee('1 of 2 done', false)->assertSee('Skipped');
});

test('Today follows the user\'s midnight for repeating tasks', function () {
    ($this->repeating)('Weekends', ['frequency' => 'weekly', 'weekdays' => [6, 7]]);

    // 11:30 pm Friday Nov 20 in Manila: not yet the weekend.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 23:30:00', 'Asia/Manila'));
    Livewire::test('today.tasks')->assertDontSee('Weekends');

    // 12:30 am Saturday in Manila (still Friday in UTC).
    $this->travelTo(CarbonImmutable::parse('2026-11-21 00:30:00', 'Asia/Manila'));
    Livewire::test('today.tasks')->assertSee('Weekends');
});

// Cross-user

test('another user\'s task ids return 404 from tick, skip and undo', function (string $page, string $method) {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Task::factory()->create(['title' => 'Theirs']);
    app(SaveTaskRepeat::class)->handle($other, $theirs, ['title' => 'Theirs'], ['frequency' => 'daily']);
    $this->actingAs($this->user);

    Livewire::test($page)->call($method, $theirs->id)->assertNotFound();

    $this->actingAs($other);
    expect(ScheduleLog::count())->toBe(0);
})->with([
    ['pages::tasks.index', 'toggle'],
    ['pages::tasks.index', 'skip'],
    ['pages::tasks.index', 'unskip'],
    ['today.tasks', 'toggle'],
    ['today.tasks', 'skip'],
    ['today.tasks', 'unskip'],
]);

test('another user\'s repeating tasks never show', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Task::factory()->create(['title' => 'Their secret chore']);
    app(SaveTaskRepeat::class)->handle($other, $theirs, ['title' => 'Their secret chore'], ['frequency' => 'daily']);
    $this->actingAs($this->user);

    $this->get(route('today'))->assertDontSee('Their secret chore');
    $this->get(route('tasks.index', ['filter' => 'upcoming']))->assertDontSee('Their secret chore');
});
