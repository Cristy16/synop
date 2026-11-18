<?php

use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Tests\Support\OwnedThing;

beforeEach(function () {
    OwnedThing::createTable();
});

test('a record is invisible to another user', function () {
    assertInvisibleToOtherUser(fn (User $owner) => OwnedThing::create(['name' => 'Mine']));
});

test('user_id is filled from the logged-in user and user() returns the owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $thing = OwnedThing::create(['name' => 'Mine']);

    expect($thing->user_id)->toBe($user->id)
        ->and($thing->user->is($user))->toBeTrue();
});

test('queries return no rows when no one is logged in', function () {
    $this->actingAs(User::factory()->create());
    OwnedThing::create(['name' => 'Mine']);

    Auth::logout();

    expect(OwnedThing::count())->toBe(0)
        ->and(OwnedThing::all())->toBeEmpty();
});

test('creating without a logged-in user requires an explicit user_id', function () {
    expect(fn () => OwnedThing::create(['name' => 'Orphan']))->toThrow(LogicException::class);

    $user = User::factory()->create();
    $thing = new OwnedThing(['name' => 'From a job']);
    $thing->user_id = $user->id;
    $thing->save();

    expect(OwnedThing::forUser($user)->count())->toBe(1);
});

test('a logged-in user cannot create a record for another user', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $this->actingAs($a);

    $thing = new OwnedThing(['name' => 'For B']);
    $thing->user_id = $b->id;

    expect(fn () => $thing->save())->toThrow(LogicException::class);

    Auth::logout();
    expect(OwnedThing::forUser($b)->count())->toBe(0);
});

test('the owner of a record cannot be changed', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $this->actingAs($a);
    $thing = OwnedThing::create(['name' => 'Mine']);

    $thing->user_id = $b->id;

    expect(fn () => $thing->save())->toThrow(LogicException::class)
        ->and(OwnedThing::findOrFail($thing->id)->user_id)->toBe($a->id);
});

test('the owner cannot be changed by a job either', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $thing = new OwnedThing(['name' => 'Mine']);
    $thing->user_id = $a->id;
    $thing->save();

    $thing->user_id = $b->id;

    expect(fn () => $thing->save())->toThrow(LogicException::class);
});

test('forUser() returns only that user\'s rows when no one is logged in', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $this->actingAs($a);
    OwnedThing::create(['name' => 'A1']);
    OwnedThing::create(['name' => 'A2']);
    $this->actingAs($b);
    OwnedThing::create(['name' => 'B1']);

    Auth::logout();

    expect(OwnedThing::forUser($a)->pluck('name')->sort()->values()->all())->toBe(['A1', 'A2'])
        ->and(OwnedThing::forUser($b->id)->pluck('name')->all())->toBe(['B1']);
});

test('money columns round-trip through the database as Money', function () {
    $this->actingAs(User::factory()->create());

    $thing = OwnedThing::create(['name' => 'Toner', 'amount_cents' => Money::parse('1,234.50')]);

    $fresh = OwnedThing::findOrFail($thing->id);

    expect($fresh->amount_cents)->toBeInstanceOf(Money::class)
        ->and($fresh->amount_cents->cents())->toBe(123450)
        ->and($fresh->getRawOriginal('amount_cents'))->toBe(123450)
        ->and($fresh->toJson())->toContain('"amount_cents":123450');
});
