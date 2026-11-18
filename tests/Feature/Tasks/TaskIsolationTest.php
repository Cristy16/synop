<?php

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\ReopenTask;
use App\Actions\Tasks\UpdateTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

test('a task is invisible to another user', function () {
    assertInvisibleToOtherUser(
        fn (User $owner) => app(CreateTask::class)->handle($owner, ['title' => 'Mine'])
    );
});

test('another user gets a 404 from every task action', function (Closure $call) {
    [$owner, $intruder] = User::factory()->count(2)->create();

    $this->actingAs($owner);
    $task = Task::factory()->create(['title' => 'Mine']);
    $snapshot = Task::findOrFail($task->id)->getAttributes();

    $this->actingAs($intruder);

    expect(fn () => $call($intruder, $task))
        ->toThrow(function (AuthorizationException $e) {
            expect($e->status())->toBe(404);
        });

    $this->actingAs($owner);
    expect(Task::findOrFail($task->id)->getAttributes())->toEqual($snapshot);
})->with([
    'update' => fn (User $u, Task $t) => app(UpdateTask::class)->handle($u, $t, ['title' => 'Hacked']),
    'complete' => fn (User $u, Task $t) => app(CompleteTask::class)->handle($u, $t),
    'reopen' => fn (User $u, Task $t) => app(ReopenTask::class)->handle($u, $t),
    'delete' => fn (User $u, Task $t) => app(DeleteTask::class)->handle($u, $t),
]);

test('the policy hides another user\'s task as not found', function (string $ability) {
    [$owner, $intruder] = User::factory()->count(2)->create();
    $this->actingAs($owner);
    $task = Task::factory()->create();

    $response = Gate::forUser($intruder)->inspect($ability, $task);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'complete', 'delete']);
