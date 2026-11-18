<?php

use App\Enums\Currency;
use App\Support\Money;

test('formats amounts with each currency\'s symbol', function (Currency $currency, string $expected) {
    expect(Money::fromCents(123450)->format($currency))->toBe($expected);
})->with([
    [Currency::PHP, '₱1,234.50'],
    [Currency::USD, '$1,234.50'],
    [Currency::EUR, '€1,234.50'],
    [Currency::GBP, '£1,234.50'],
    [Currency::SGD, 'S$1,234.50'],
    [Currency::AUD, 'A$1,234.50'],
    [Currency::CAD, 'C$1,234.50'],
    [Currency::HKD, 'HK$1,234.50'],
]);

test('negative amounts put the minus sign before the symbol', function () {
    expect(Money::fromCents(-50000)->format(Currency::HKD))->toBe("\u{2212}HK$500.00");
});

test('parses amounts typed with any currency symbol', function (string $input) {
    expect(Money::parse($input)->cents())->toBe(100000);
})->with(['$1,000', 'S$ 1,000', 'HK$1000.00', '€1,000', '£1000', '₱1,000']);

test('gives a plain decimal string for form fields', function (int $cents, string $expected) {
    expect(Money::fromCents($cents)->toDecimalString())->toBe($expected);
})->with([
    [150050, '1500.50'],
    [0, '0.00'],
    [5, '0.05'],
    [-12345, '-123.45'],
]);

test('every currency has a label', function () {
    foreach (Currency::cases() as $currency) {
        expect($currency->label())->not->toBeEmpty();
    }
});
