<?php

use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\UpdateTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('the owner may view, update, complete and delete a manual task', function () {
    $task = Task::factory()->create();

    foreach (['view', 'update', 'complete', 'delete'] as $ability) {
        expect(Gate::allows($ability, $task))->toBeTrue("owner was denied: {$ability}");
    }
});

test('a task created by an activity or a debt cannot be deleted directly', function (string $source) {
    $task = Task::factory()->create(['source_type' => $source, 'source_id' => 1]);

    $response = Gate::inspect('delete', $task);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBeNull('should be a normal "not allowed", not a 404')
        ->and($response->message())->toContain("Delete the {$source} instead");

    expect(fn () => app(DeleteTask::class)->handle($this->user, $task))
        ->toThrow(AuthorizationException::class);

    expect(Task::whereKey($task->id)->exists())->toBeTrue();
})->with(['activity', 'debt']);

test('a task created by an activity or a debt can still be edited and completed', function (string $source) {
    $task = Task::factory()->create(['source_type' => $source, 'source_id' => 1]);

    app(UpdateTask::class)->handle($this->user, $task, ['title' => 'Edited']);
    app(CompleteTask::class)->handle($this->user, $task);

    expect(Task::findOrFail($task->id))
        ->title->toBe('Edited')
        ->completed_at->not->toBeNull();
})->with(['activity', 'debt']);

test('a low-stock task (source = product) can be deleted', function () {
    $task = Task::factory()->create(['source_type' => 'product', 'source_id' => 1]);

    app(DeleteTask::class)->handle($this->user, $task);

    expect(Task::whereKey($task->id)->exists())->toBeFalse();
});
