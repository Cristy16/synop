<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Prove that a BelongsToUser model's record is invisible to another user.
 *
 * $createFor receives the owner (already logged in) and returns one record it created:
 *
 *     assertInvisibleToOtherUser(fn (User $owner) => Task::create(['title' => 'Mine']));
 *
 * User B must not be able to list, view, edit, delete or link to user A's record.
 *
 * @param  Closure(User): Model  $createFor
 */
function assertInvisibleToOtherUser(Closure $createFor): void
{
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    test()->actingAs($owner);
    $record = $createFor($owner);
    $model = $record::class;
    $id = $record->getKey();
    $snapshot = $model::query()->findOrFail($id)->getAttributes();

    expect($record->getAttribute('user_id'))->toBe($owner->id, 'user_id was not filled with the owner');

    $linkRule = fn (User $user) => Validator::make(
        ['linked_id' => $id],
        ['linked_id' => [Rule::exists($record->getTable(), $record->getKeyName())->where('user_id', $user->id)]],
    );
    expect($linkRule($owner)->passes())->toBeTrue('owner should be able to link to their own record');

    test()->actingAs($intruder);

    // List
    expect($model::query()->count())->toBe(0, 'another user can list the record');

    // View (findOrFail → 404 through route model binding)
    expect($model::query()->find($id))->toBeNull('another user can view the record');
    expect(fn () => $model::query()->findOrFail($id))->toThrow(ModelNotFoundException::class);

    // Edit
    expect($model::query()->whereKey($id)->update([$record->getKeyName() => $id]))
        ->toBe(0, 'another user can update the record');

    // Delete
    expect($model::query()->whereKey($id)->delete())->toBe(0, 'another user can delete the record');

    // Link
    expect($linkRule($intruder)->fails())->toBeTrue('another user can link to the record');

    // The owner's record is untouched.
    test()->actingAs($owner);
    expect($model::query()->findOrFail($id)->getAttributes())->toEqual($snapshot);
}
