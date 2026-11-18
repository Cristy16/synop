<?php

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->cast = new MoneyCast;
    $this->model = new class extends Model {};
});

test('reads integer centavos as Money', function () {
    $money = $this->cast->get($this->model, 'amount_cents', 123450, []);

    expect($money)->toBeInstanceOf(Money::class)
        ->and($money->cents())->toBe(123450);
});

test('reads numeric strings from the driver as Money', function () {
    expect($this->cast->get($this->model, 'amount_cents', '-500', [])->cents())->toBe(-500);
});

test('reads null as null', function () {
    expect($this->cast->get($this->model, 'amount_cents', null, []))->toBeNull();
});

test('rejects a non-integer value from the database', function () {
    $this->cast->get($this->model, 'amount_cents', '12.50', []);
})->throws(InvalidArgumentException::class);

test('writes Money and integers as integer centavos', function () {
    expect($this->cast->set($this->model, 'amount_cents', Money::fromCents(123450), []))->toBe(123450)
        ->and($this->cast->set($this->model, 'amount_cents', 99, []))->toBe(99)
        ->and($this->cast->set($this->model, 'amount_cents', null, []))->toBeNull();
});

test('refuses strings and floats', function (mixed $value) {
    $this->cast->set($this->model, 'amount_cents', $value, []);
})->with(['12.50', 12.5])->throws(InvalidArgumentException::class);
