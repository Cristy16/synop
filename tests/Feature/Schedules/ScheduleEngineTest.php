<?php

use App\Enums\Frequency;
use App\Models\ScheduleRule;
use App\Services\ScheduleEngine;

/*
 * The engine is pure PHP (D-015, D-042): these rules are never saved.
 * Dates in and out are local calendar dates as Y-m-d strings.
 */

function scheduleRule(array $attributes): ScheduleRule
{
    return (new ScheduleRule)->forceFill([
        'starts_on' => '2026-11-01',
        'ends_on' => null,
        'paused_at' => null,
        'interval' => null,
        'weekdays' => null,
        'day_of_month' => null,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->engine = new ScheduleEngine;
    $this->occurs = fn (ScheduleRule $rule, string $date) => $this->engine->occursOn($rule, $date);
});

// Range: start, end, pause — for every frequency

test('nothing before the start date, and the start date itself counts', function () {
    $rule = scheduleRule(['frequency' => Frequency::Daily, 'starts_on' => '2026-11-10']);

    expect(($this->occurs)($rule, '2026-11-09'))->toBeFalse()
        ->and(($this->occurs)($rule, '2026-11-10'))->toBeTrue();
});

test('the end date is included, the day after is not', function () {
    $rule = scheduleRule(['frequency' => Frequency::Daily, 'ends_on' => '2026-11-30']);

    expect(($this->occurs)($rule, '2026-11-30'))->toBeTrue()
        ->and(($this->occurs)($rule, '2026-12-01'))->toBeFalse();
});

test('with no end date it goes on', function () {
    expect(($this->occurs)(scheduleRule(['frequency' => Frequency::Daily]), '2030-06-15'))->toBeTrue();
});

test('a paused rule never occurs', function (Frequency $frequency, array $fields) {
    $rule = scheduleRule(['frequency' => $frequency, 'paused_at' => now(), ...$fields]);

    expect(($this->occurs)($rule, '2026-11-01'))->toBeFalse()
        ->and($this->engine->occurrencesBetween($rule, '2026-11-01', '2026-11-30'))->toBe([])
        ->and($this->engine->nextOnOrAfter($rule, '2026-11-01'))->toBeNull();
})->with([
    'daily' => [Frequency::Daily, []],
    'every 3 days' => [Frequency::EveryNDays, ['interval' => 3]],
    'weekly' => [Frequency::Weekly, ['weekdays' => [7]]],
    'monthly' => [Frequency::Monthly, ['day_of_month' => 1]],
]);

// Daily

test('daily occurs every day', function () {
    $rule = scheduleRule(['frequency' => Frequency::Daily]);

    expect($this->engine->occurrencesBetween($rule, '2026-11-28', '2026-12-02'))
        ->toBe(['2026-11-28', '2026-11-29', '2026-11-30', '2026-12-01', '2026-12-02']);
});

// Every N days

test('every N days counts from the start date', function () {
    $rule = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 3, 'starts_on' => '2026-11-01']);

    expect($this->engine->occurrencesBetween($rule, '2026-11-01', '2026-11-10'))
        ->toBe(['2026-11-01', '2026-11-04', '2026-11-07', '2026-11-10']);
});

test('every N days carries on across a month end', function () {
    $rule = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 3, 'starts_on' => '2026-11-01']);

    // Nov 1 + 30 days = Dec 1, and 30 is a multiple of 3.
    expect(($this->occurs)($rule, '2026-11-28'))->toBeTrue()
        ->and(($this->occurs)($rule, '2026-12-01'))->toBeTrue()
        ->and(($this->occurs)($rule, '2026-11-30'))->toBeFalse();
});

test('every N days carries on across a year end and a leap day', function () {
    $newYear = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 2, 'starts_on' => '2026-12-30']);
    $leap = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 2, 'starts_on' => '2028-02-27']);

    expect(($this->occurs)($newYear, '2027-01-01'))->toBeTrue()
        ->and(($this->occurs)($newYear, '2026-12-31'))->toBeFalse()
        ->and($this->engine->occurrencesBetween($leap, '2028-02-27', '2028-03-03'))
        ->toBe(['2028-02-27', '2028-02-29', '2028-03-02']);
});

// Weekly

test('weekly occurs on the chosen ISO weekdays (Mon = 1)', function () {
    $mwf = scheduleRule(['frequency' => Frequency::Weekly, 'weekdays' => [1, 3, 5]]);

    // Nov 16, 2026 is a Monday.
    expect($this->engine->occurrencesBetween($mwf, '2026-11-16', '2026-11-22'))
        ->toBe(['2026-11-16', '2026-11-18', '2026-11-20']);
});

test('Sunday is weekday 7', function () {
    $sundays = scheduleRule(['frequency' => Frequency::Weekly, 'weekdays' => [7]]);

    expect(($this->occurs)($sundays, '2026-11-22'))->toBeTrue()
        ->and(($this->occurs)($sundays, '2026-11-23'))->toBeFalse();
});

// Monthly

test('monthly occurs on the chosen day', function () {
    $rule = scheduleRule(['frequency' => Frequency::Monthly, 'day_of_month' => 15]);

    expect($this->engine->occurrencesBetween($rule, '2026-11-01', '2027-01-31'))
        ->toBe(['2026-11-15', '2026-12-15', '2027-01-15']);
});

test('day 29–31 falls on the last day of shorter months (D-014)', function (int $day, string $date, bool $expected) {
    $rule = scheduleRule(['frequency' => Frequency::Monthly, 'day_of_month' => $day, 'starts_on' => '2026-01-01']);

    expect(($this->occurs)($rule, $date))->toBe($expected);
})->with([
    'day 31 in November → Nov 30' => [31, '2026-11-30', true],
    'day 31 in December → Dec 31' => [31, '2026-12-31', true],
    'day 31 not on Dec 30' => [31, '2026-12-30', false],
    'day 31 in April → Apr 30' => [31, '2026-04-30', true],
    'day 30 in April → Apr 30' => [30, '2026-04-30', true],
    'day 29 in Feb 2026 → Feb 28' => [29, '2026-02-28', true],
    'day 31 in Feb 2026 → Feb 28' => [31, '2026-02-28', true],
    'day 31 not on Feb 27' => [31, '2026-02-27', false],
    'day 29 in Feb 2028 (leap) → Feb 29' => [29, '2028-02-29', true],
    'day 31 in Feb 2028 (leap) → Feb 29' => [31, '2028-02-29', true],
    'day 31 in Feb 2028 not on Feb 28' => [31, '2028-02-28', false],
    'day 28 stays on the 28th' => [28, '2026-02-28', true],
]);

test('monthly day 31 lands once in each month across a year', function () {
    $rule = scheduleRule(['frequency' => Frequency::Monthly, 'day_of_month' => 31, 'starts_on' => '2026-01-01']);

    $dates = $this->engine->occurrencesBetween($rule, '2026-01-01', '2026-12-31');

    expect($dates)->toHaveCount(12)
        ->and($dates[1])->toBe('2026-02-28')
        ->and($dates[3])->toBe('2026-04-30');
});

// Time zones and daylight saving

test('day counts ignore daylight saving changes', function (string $timezone) {
    $previous = date_default_timezone_get();
    date_default_timezone_set($timezone);

    try {
        // America/New_York: clocks go forward on 2026-03-08 and back on 2026-11-01.
        $spring = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 2, 'starts_on' => '2026-03-06']);
        $autumn = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 2, 'starts_on' => '2026-10-30']);

        expect($this->engine->occurrencesBetween($spring, '2026-03-06', '2026-03-12'))
            ->toBe(['2026-03-06', '2026-03-08', '2026-03-10', '2026-03-12'])
            ->and($this->engine->occurrencesBetween($autumn, '2026-10-30', '2026-11-03'))
            ->toBe(['2026-10-30', '2026-11-01', '2026-11-03']);
    } finally {
        date_default_timezone_set($previous);
    }
})->with(['America/New_York', 'Europe/London', 'Australia/Sydney', 'Asia/Manila']);

// occurrencesBetween and nextOnOrAfter

test('occurrencesBetween is clamped to the rule\'s own range', function () {
    $rule = scheduleRule(['frequency' => Frequency::Daily, 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12']);

    expect($this->engine->occurrencesBetween($rule, '2026-11-01', '2026-11-30'))
        ->toBe(['2026-11-10', '2026-11-11', '2026-11-12'])
        ->and($this->engine->occurrencesBetween($rule, '2026-12-01', '2026-12-31'))->toBe([]);
});

test('occurrencesBetween allows up to 366 days', function () {
    $rule = scheduleRule(['frequency' => Frequency::Daily, 'starts_on' => '2028-01-01']);

    expect($this->engine->occurrencesBetween($rule, '2028-01-01', '2028-12-31'))->toHaveCount(366);
});

test('occurrencesBetween refuses ranges over 366 days, or backwards', function (string $from, string $to) {
    $this->engine->occurrencesBetween(scheduleRule(['frequency' => Frequency::Daily]), $from, $to);
})->with([
    'too long' => ['2026-01-01', '2027-01-03'],
    'backwards' => ['2026-11-10', '2026-11-09'],
])->throws(InvalidArgumentException::class);

test('nextOnOrAfter finds the next occurrence', function () {
    $monthly = scheduleRule(['frequency' => Frequency::Monthly, 'day_of_month' => 31]);
    $every3 = scheduleRule(['frequency' => Frequency::EveryNDays, 'interval' => 3, 'starts_on' => '2026-11-01']);
    $later = scheduleRule(['frequency' => Frequency::Daily, 'starts_on' => '2026-12-05']);

    expect($this->engine->nextOnOrAfter($monthly, '2026-11-01'))->toBe('2026-11-30')
        ->and($this->engine->nextOnOrAfter($every3, '2026-11-04'))->toBe('2026-11-04')
        ->and($this->engine->nextOnOrAfter($every3, '2026-11-05'))->toBe('2026-11-07')
        ->and($this->engine->nextOnOrAfter($later, '2026-11-20'))->toBe('2026-12-05');
});

test('nextOnOrAfter returns null after the end date', function () {
    $rule = scheduleRule(['frequency' => Frequency::Weekly, 'weekdays' => [1], 'ends_on' => '2026-11-20']);

    expect($this->engine->nextOnOrAfter($rule, '2026-11-17'))->toBeNull();
});

// Bad input

test('dates must be real Y-m-d dates', function (string $date) {
    ($this->occurs)(scheduleRule(['frequency' => Frequency::Daily]), $date);
})->with(['2026-02-30', '20/11/2026', 'tomorrow', ''])->throws(InvalidArgumentException::class);
