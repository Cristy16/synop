<?php

use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Tests\Support\OwnedThing;

test('polymorphic types are stored as aliases, not class names', function () {
    expect((new User)->getMorphClass())->toBe('user')
        ->and((new Task)->getMorphClass())->toBe('task')
        ->and((new Debt)->getMorphClass())->toBe('debt')
        ->and((new DebtPayment)->getMorphClass())->toBe('debt_payment');
});

test('a model missing from the morph map cannot be used polymorphically', function () {
    expect(fn () => (new OwnedThing)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});
