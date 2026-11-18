<?php

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskPriority;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Support\SourceThing;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// Create

test('a task is created with defaults', function () {
    $task = app(CreateTask::class)->handle($this->user, ['title' => 'Buy toner']);

    $fresh = Task::findOrFail($task->id);

    expect($fresh->title)->toBe('Buy toner')
        ->and($fresh->user_id)->toBe($this->user->id)
        ->and($fresh->priority)->toBe(TaskPriority::Normal)
        ->and($fresh->notes)->toBeNull()
        ->and($fresh->due_on)->toBeNull()
        ->and($fresh->completed_at)->toBeNull()
        ->and($fresh->source_type)->toBeNull()
        ->and($fresh->source_id)->toBeNull();
});

test('a task is created with all fields, dates kept as the user typed them', function () {
    $task = app(CreateTask::class)->handle($this->user, [
        'title' => 'Pay rent',
        'notes' => 'Bank transfer',
        'due_on' => '2026-11-20',
        'due_time' => '08:00',
        'priority' => 'high',
    ]);

    $fresh = Task::findOrFail($task->id);

    expect($fresh->notes)->toBe('Bank transfer')
        ->and($fresh->due_on?->toDateString())->toBe('2026-11-20')
        ->and(substr((string) $fresh->due_time, 0, 5))->toBe('08:00')
        ->and($fresh->priority)->toBe(TaskPriority::High);
});

test('a task can be created by a job with no one logged in', function () {
    Auth::logout();

    $task = app(CreateTask::class)->handle($this->user, ['title' => 'From a job']);

    expect(Task::forUser($this->user)->whereKey($task->id)->exists())->toBeTrue();
});

test('invalid input is rejected', function (array $input, string $field) {
    expect(fn () => app(CreateTask::class)->handle($this->user, $input))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Task::count())->toBe(0);
})->with([
    'missing title' => [[], 'title'],
    'title too long' => [['title' => str_repeat('a', 256)], 'title'],
    'date in the wrong format' => [['title' => 'x', 'due_on' => '20/11/2026'], 'due_on'],
    'impossible date' => [['title' => 'x', 'due_on' => '2026-02-30'], 'due_on'],
    'impossible time' => [['title' => 'x', 'due_time' => '25:00'], 'due_time'],
    'unknown priority' => [['title' => 'x', 'priority' => 'urgent'], 'priority'],
]);

test('user_id in the input is ignored', function () {
    $other = User::factory()->create();

    $task = app(CreateTask::class)->handle($this->user, ['title' => 'Mine', 'user_id' => $other->id]);

    expect($task->user_id)->toBe($this->user->id);
});

// Source

test('a task can be linked to a source, stored as its morph alias', function () {
    SourceThing::register();
    $source = SourceThing::create(['name' => 'Midterm exam']);

    $task = app(CreateTask::class)->handle($this->user, ['title' => 'Study'], $source);

    $fresh = Task::findOrFail($task->id);

    expect($fresh->getRawOriginal('source_type'))->toBe('source_thing')
        ->and($fresh->source_id)->toBe($source->id)
        ->and($fresh->source)->toBeInstanceOf(SourceThing::class)
        ->and($fresh->source?->is($source))->toBeTrue();
});

test('a source can have only one task', function () {
    SourceThing::register();
    $source = SourceThing::create(['name' => 'Midterm exam']);

    app(CreateTask::class)->handle($this->user, ['title' => 'Study'], $source);

    expect(fn () => app(CreateTask::class)->handle($this->user, ['title' => 'Again'], $source))
        ->toThrow(LogicException::class);

    expect(Task::count())->toBe(1);
});

test('manual tasks (no source) are not limited by the one-per-source rule', function () {
    app(CreateTask::class)->handle($this->user, ['title' => 'One']);
    app(CreateTask::class)->handle($this->user, ['title' => 'Two']);

    expect(Task::count())->toBe(2);
});

test('a task cannot be linked to another user\'s source', function () {
    SourceThing::register();
    $other = User::factory()->create();
    $this->actingAs($other);
    $theirs = SourceThing::create(['name' => 'Their debt']);
    $this->actingAs($this->user);

    expect(fn () => app(CreateTask::class)->handle($this->user, ['title' => 'Sneaky'], $theirs))
        ->toThrow(LogicException::class);

    expect(Task::count())->toBe(0);
});

// Update

test('a task can be updated', function () {
    $task = Task::factory()->create(['priority' => TaskPriority::High]);

    app(UpdateTask::class)->handle($this->user, $task, [
        'title' => 'New title',
        'notes' => 'New notes',
        'due_on' => '2026-12-01',
    ]);

    $fresh = Task::findOrFail($task->id);

    expect($fresh->title)->toBe('New title')
        ->and($fresh->notes)->toBe('New notes')
        ->and($fresh->due_on?->toDateString())->toBe('2026-12-01')
        ->and($fresh->priority)->toBe(TaskPriority::High, 'priority left out keeps its value');
});

test('updating does not change the source or completion', function () {
    $task = Task::factory()->completed()->create(['source_type' => 'debt', 'source_id' => 7]);
    $completedAt = $task->getRawOriginal('completed_at');

    app(UpdateTask::class)->handle($this->user, $task, [
        'title' => 'Edited',
        'source_type' => 'product',
        'source_id' => 99,
        'completed_at' => null,
    ]);

    $fresh = Task::findOrFail($task->id);

    expect($fresh->source_type)->toBe('debt')
        ->and($fresh->source_id)->toBe(7)
        ->and($fresh->getRawOriginal('completed_at'))->toBe($completedAt);
});

test('an update with invalid input changes nothing', function () {
    $task = Task::factory()->create(['title' => 'Original']);

    expect(fn () => app(UpdateTask::class)->handle($this->user, $task, ['title' => '']))
        ->toThrow(ValidationException::class);

    expect(Task::findOrFail($task->id)->title)->toBe('Original');
});

// Complete / reopen

test('completing a task stores the moment in UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:30:00', 'Asia/Manila'));
    $task = Task::factory()->create();

    app(CompleteTask::class)->handle($this->user, $task);

    expect(Task::findOrFail($task->id)->getRawOriginal('completed_at'))->toBe('2026-11-18 15:30:00');
});

test('completing a completed task keeps the first completion time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:00:00', 'UTC'));
    $task = Task::factory()->create();
    app(CompleteTask::class)->handle($this->user, $task);

    $this->travelTo(CarbonImmutable::parse('2026-11-19 08:00:00', 'UTC'));
    app(CompleteTask::class)->handle($this->user, $task);

    expect(Task::findOrFail($task->id)->getRawOriginal('completed_at'))->toBe('2026-11-18 08:00:00');
});

test('a completed task can be reopened', function () {
    $task = Task::factory()->completed()->create();

    app(ReopenTask::class)->handle($this->user, $task);

    expect(Task::findOrFail($task->id)->isCompleted())->toBeFalse();
});

// Delete

test('a manual task can be deleted', function () {
    $task = Task::factory()->create();

    app(DeleteTask::class)->handle($this->user, $task);

    expect(Task::count())->toBe(0);
});

test('tasks are deleted with their user', function () {
    Task::factory()->count(2)->create();

    $this->user->delete();

    expect(Task::forUser($this->user)->count())->toBe(0);
});
