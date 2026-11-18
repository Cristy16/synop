<?php

use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Tests\Support\OwnedThing;

test('polymorphic types are stored as aliases, not class names', function () {
    expect((new User)->getMorphClass())->toBe('user');
});

test('a model missing from the morph map cannot be used polymorphically', function () {
    expect(fn () => (new OwnedThing)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});
