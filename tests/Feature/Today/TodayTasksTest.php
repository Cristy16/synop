<?php

use App\Models\Task;
use App\Models\User;
use App\Services\TaskList;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 9:00 am in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
});

function todayTitles(User $user): array
{
    return app(TaskList::class)->dueByToday($user)->pluck('title')->all();
}

test('shows overdue and today\'s open tasks, not upcoming or undated ones', function () {
    Task::factory()->dueOn('2026-11-19', '14:00')->create(['title' => 'today 2 pm']);
    Task::factory()->dueOn('2026-11-19', '08:00')->create(['title' => 'today 8 am']);
    Task::factory()->dueOn('2026-11-17')->create(['title' => 'overdue']);
    Task::factory()->dueOn('2026-11-20')->create(['title' => 'tomorrow']);
    Task::factory()->create(['title' => 'no date']);

    expect(todayTitles($this->user))->toBe(['overdue', 'today 8 am', 'today 2 pm']);

    Livewire::test('today.tasks')
        ->assertSee(['overdue', 'today 8 am', 'today 2 pm'])
        ->assertSee('Overdue · Nov 17')
        ->assertDontSee('tomorrow')
        ->assertDontSee('no date')
        ->assertSee('0 of 3 done');
});

test('a task ticked today stays, crossed out, and counts as done', function () {
    $task = Task::factory()->dueOn('2026-11-19')->create(['title' => 'Submit thesis']);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'Other']);

    Livewire::test('today.tasks')
        ->call('toggle', $task->id)
        ->assertSee('Submit thesis')
        ->assertSee('line-through')
        ->assertSee('1 of 2 done');

    expect($task->fresh()->isCompleted())->toBeTrue();
});

test('an overdue task ticked today stays too, without the overdue label', function () {
    $task = Task::factory()->dueOn('2026-11-15')->create(['title' => 'Late bill']);

    Livewire::test('today.tasks')
        ->call('toggle', $task->id)
        ->assertSee('Late bill')
        ->assertDontSee('Overdue ·')
        ->assertSee('1 of 1 done');
});

test('unticking reopens the task', function () {
    $task = Task::factory()->dueOn('2026-11-19')->create(['completed_at' => now()]);

    Livewire::test('today.tasks')->call('toggle', $task->id);

    expect($task->fresh()->isCompleted())->toBeFalse();
});

test('tasks ticked before today (in the user\'s timezone) are gone', function () {
    // Completed 11:30 pm Nov 18 Manila = 15:30 UTC Nov 18.
    Task::factory()->dueOn('2026-11-18')->create([
        'title' => 'done last night',
        'completed_at' => CarbonImmutable::parse('2026-11-18 23:30:00', 'Asia/Manila')->utc(),
    ]);
    // Completed 12:30 am Nov 19 Manila = still Nov 18 in UTC, but today for the user.
    Task::factory()->dueOn('2026-11-18')->create([
        'title' => 'done after midnight',
        'completed_at' => CarbonImmutable::parse('2026-11-19 00:30:00', 'Asia/Manila')->utc(),
    ]);

    expect(todayTitles($this->user))->toBe(['done after midnight']);
});

test('an undated task finished today does not appear', function () {
    Task::factory()->create(['title' => 'undated', 'completed_at' => now()]);

    expect(todayTitles($this->user))->toBe([]);
});

test('the empty state shows when nothing is due', function () {
    Livewire::test('today.tasks')
        ->assertSee('Nothing due today.')
        ->assertDontSee('of 0 done')
        ->assertSee(route('tasks.index'));
});

test('another user\'s tasks are not shown and cannot be ticked', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Task::factory()->dueOn('2026-11-19')->create(['title' => 'Their secret']);

    $this->actingAs($this->user);

    Livewire::test('today.tasks')
        ->assertDontSee('Their secret')
        ->call('toggle', $theirs->id)
        ->assertNotFound();

    $this->actingAs($other);
    expect($theirs->fresh()->isCompleted())->toBeFalse();
});
