<?php

use App\Support\Money;

test('parses user input into centavos', function (string $input, int $cents) {
    expect(Money::parse($input)->cents())->toBe($cents);
})->with([
    ['1,234.5', 123450],
    ['1,234.50', 123450],
    ['0.05', 5],
    ['.5', 50],
    ['12', 1200],
    ['12.', 1200],
    ['₱ 1,000', 100000],
    ['-₱500', -50000],
    ['  99.99 ', 9999],
    ['-500', -50000],
    ['-0.01', -1],
    ['007', 700],
    ['999999999999999.99', 99999999999999999],
]);

test('rejects invalid input', function (string $input) {
    Money::parse($input);
})->with(['', '.', '-', 'abc', '1.234', '1.2.3', '1e3', '12a', '1000000000000000', '12 34', '1 000'])
    ->throws(InvalidArgumentException::class);

test('add and subtract return new values', function () {
    $a = Money::fromCents(1000);
    $b = Money::fromCents(250);

    expect($a->add($b)->cents())->toBe(1250)
        ->and($a->subtract($b)->cents())->toBe(750)
        ->and($b->subtract($a)->isNegative())->toBeTrue()
        ->and($a->cents())->toBe(1000);
});

test('compares amounts', function () {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::fromCents(5)->isZero())->toBeFalse()
        ->and(Money::fromCents(5)->equals(Money::parse('0.05')))->toBeTrue()
        ->and(Money::fromCents(5)->isNegative())->toBeFalse();
});

test('formats for display', function (int $cents, string $expected) {
    expect(Money::fromCents($cents)->format())->toBe($expected);
})->with([
    [123450, '₱1,234.50'],
    [0, '₱0.00'],
    [5, '₱0.05'],
    [100000000, '₱1,000,000.00'],
    [-50000, "\u{2212}₱500.00"],
    [-1, "\u{2212}₱0.01"],
]);

test('becomes its centavos integer in JSON', function () {
    expect(json_encode(['amount_cents' => Money::fromCents(12345)]))->toBe('{"amount_cents":12345}');
});

test('money() helper formats Money, integers and null', function () {
    expect(money(Money::fromCents(123450)))->toBe('₱1,234.50')
        ->and(money(99))->toBe('₱0.99')
        ->and(money(null))->toBe('');
});
