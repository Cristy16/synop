<?php

use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));
});

test('the tasks page is displayed and linked from the sidebar', function () {
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'Buy toner']);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('Buy toner')
        ->assertSee(route('tasks.index'));
});

test('guests are redirected to the login page', function () {
    auth()->logout();

    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('the tab is read from the address bar', function () {
    Task::factory()->dueOn('2026-11-01')->create(['title' => 'Late one']);

    Livewire::withQueryParams(['filter' => 'overdue'])
        ->test('pages::tasks.index')
        ->assertSee('Late one');
});

test('an unknown tab falls back to Today', function () {
    Livewire::withQueryParams(['filter' => 'nonsense'])
        ->test('pages::tasks.index')
        ->assertSee('Nothing due today.');
});

test('quick-add on the Today tab makes a task due today', function () {
    Livewire::test('pages::tasks.index')
        ->set('quickTitle', '  Call the dentist  ')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('quickTitle', '')
        ->assertSee('Call the dentist');

    $task = Task::sole();
    expect($task->title)->toBe('Call the dentist')
        ->and($task->due_on?->toDateString())->toBe('2026-11-19');
});

test('quick-add on other tabs makes a task with no date', function (string $tab) {
    Livewire::test('pages::tasks.index')
        ->set('filter', $tab)
        ->set('quickTitle', 'Someday thing')
        ->call('add')
        ->assertHasNoErrors();

    expect(Task::sole()->due_on)->toBeNull();
})->with(['upcoming', 'overdue', 'no-date', 'done']);

test('quick-add with an empty title shows an error', function () {
    Livewire::test('pages::tasks.index')
        ->set('quickTitle', '   ')
        ->call('add')
        ->assertHasErrors('quickTitle');

    expect(Task::count())->toBe(0);
});

test('the checkbox completes and reopens a task', function () {
    $task = Task::factory()->dueOn('2026-11-19')->create();

    $page = Livewire::test('pages::tasks.index')->call('toggle', $task->id);
    expect($task->fresh()->isCompleted())->toBeTrue();

    $page->call('toggle', $task->id);
    expect($task->fresh()->isCompleted())->toBeFalse();
});

test('a task can be edited', function () {
    $task = Task::factory()->dueOn('2026-11-19', '08:00')->create(['title' => 'Old']);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertSet('editingId', $task->id)
        ->assertSet('title', 'Old')
        ->assertSet('due_on', '2026-11-19')
        ->assertSet('due_time', '08:00')
        ->set('title', 'New')
        ->set('notes', 'Some notes')
        ->set('due_on', '')
        ->set('due_time', '')
        ->set('priority', 'high')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editingId', null);

    $fresh = $task->fresh();
    expect($fresh->title)->toBe('New')
        ->and($fresh->notes)->toBe('Some notes')
        ->and($fresh->due_on)->toBeNull()
        ->and($fresh->due_time)->toBeNull()
        ->and($fresh->priority)->toBe(TaskPriority::High);
});

test('editing with bad input shows errors and saves nothing', function () {
    $task = Task::factory()->create(['title' => 'Keep me']);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->set('title', '')
        ->set('due_on', 'not-a-date')
        ->call('save')
        ->assertHasErrors(['title', 'due_on']);

    expect($task->fresh()->title)->toBe('Keep me');
});

test('the editing id cannot be changed from the browser', function () {
    $task = Task::factory()->create();

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->set('editingId', $task->id + 1);
})->throws(CannotUpdateLockedPropertyException::class);

test('a task can be deleted from the edit window', function () {
    $task = Task::factory()->create();

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertSee('Delete this task?')
        ->call('delete');

    expect(Task::count())->toBe(0);
});

test('a task from a debt or activity shows a message instead of Delete, and refuses delete', function () {
    $task = Task::factory()->create(['source_type' => 'debt', 'source_id' => 1]);

    Livewire::test('pages::tasks.index')
        ->call('edit', $task->id)
        ->assertSee('delete the debt instead')
        ->assertDontSee('Delete this task?')
        ->call('delete')
        ->assertForbidden();

    expect(Task::count())->toBe(1);
});

test('another user\'s task id returns 404 from every page action', function (string $method) {
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = Task::factory()->create(['title' => 'Theirs']);
    $snapshot = $theirs->fresh()->getAttributes();

    $this->actingAs($this->user);

    Livewire::test('pages::tasks.index')
        ->call($method, $theirs->id)
        ->assertNotFound();

    $this->actingAs($other);
    expect(Task::findOrFail($theirs->id)->getAttributes())->toEqual($snapshot);
})->with(['toggle', 'edit']);

test('another user\'s tasks are not shown', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    Task::factory()->dueOn('2026-11-19')->create(['title' => 'Their secret']);

    $this->actingAs($this->user);

    $this->get(route('tasks.index'))->assertOk()->assertDontSee('Their secret');
});
