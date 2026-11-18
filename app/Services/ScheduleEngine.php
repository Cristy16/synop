<?php

namespace App\Services;

use App\Enums\Frequency;
use App\Models\ScheduleRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Answers "does this rule occur on date D?" and "which dates between D1 and D2?"
 * Pure PHP: it never queries the database (D-015, D-042).
 *
 * Dates in and out are local calendar dates as Y-m-d strings, exactly as the
 * user means them (rule 9). Internally they are read as midnight UTC, so day
 * counts never meet a daylight-saving change.
 */
class ScheduleEngine
{
    /** Longest range occurrencesBetween() will walk. */
    public const MAX_RANGE_DAYS = 366;

    private const SECONDS_PER_DAY = 86400;

    public function occursOn(ScheduleRule $rule, string $date): bool
    {
        $day = $this->parse($date);

        if (! $this->isActiveOn($rule, $day)) {
            return false;
        }

        return match ($rule->frequency) {
            Frequency::Daily => true,
            // Counted from the start date, so the start date is always an occurrence.
            Frequency::EveryNDays => $this->daysBetween($this->startOf($rule), $day) % max(1, (int) $rule->interval) === 0,
            // ISO weekday: Monday = 1 … Sunday = 7.
            Frequency::Weekly => in_array((int) $day->format('N'), array_map('intval', $rule->weekdays ?? []), true),
            // Day 29–31 falls on the last day of shorter months (D-014).
            Frequency::Monthly => (int) $day->format('j') === min((int) $rule->day_of_month, (int) $day->format('t')),
        };
    }

    /**
     * Every occurrence from $from to $to (both included), oldest first.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException for a backwards range or one longer than 366 days
     */
    public function occurrencesBetween(ScheduleRule $rule, string $from, string $to): array
    {
        $first = $this->parse($from);
        $last = $this->parse($to);

        if ($last < $first) {
            throw new InvalidArgumentException("The range ends ({$to}) before it starts ({$from}).");
        }

        if ($this->daysBetween($first, $last) + 1 > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('Ask for '.self::MAX_RANGE_DAYS.' days or fewer at a time.');
        }

        if ($rule->paused_at !== null) {
            return [];
        }

        // Only walk the part of the range the rule covers.
        $first = max($first, $this->startOf($rule));
        $end = $this->endOf($rule);
        $last = $end === null ? $last : min($last, $end);

        $dates = [];

        for ($day = $first; $day <= $last; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');

            if ($this->occursOn($rule, $date)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /**
     * The first occurrence on or after $date, or null if there is none
     * (paused, or past the end date).
     */
    public function nextOnOrAfter(ScheduleRule $rule, string $date): ?string
    {
        if ($rule->paused_at !== null) {
            return null;
        }

        $day = max($this->parse($date), $this->startOf($rule));
        $end = $this->endOf($rule);

        // The longest gap between occurrences is 365 days (every_n_days), so a year is always enough.
        for ($i = 0; $i < self::MAX_RANGE_DAYS; $i++, $day = $day->addDay()) {
            if ($end !== null && $day > $end) {
                return null;
            }

            if ($this->occursOn($rule, $day->format('Y-m-d'))) {
                return $day->format('Y-m-d');
            }
        }

        return null;
    }

    private function isActiveOn(ScheduleRule $rule, CarbonImmutable $day): bool
    {
        if ($rule->paused_at !== null || $day < $this->startOf($rule)) {
            return false;
        }

        $end = $this->endOf($rule);

        return $end === null || $day <= $end;
    }

    private function startOf(ScheduleRule $rule): CarbonImmutable
    {
        return $this->parse($rule->starts_on->toDateString());
    }

    private function endOf(ScheduleRule $rule): ?CarbonImmutable
    {
        return $rule->ends_on === null ? null : $this->parse($rule->ends_on->toDateString());
    }

    /**
     * Whole days from $from to $to. Both are UTC midnights, so this is exact.
     */
    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), self::SECONDS_PER_DAY);
    }

    /**
     * A Y-m-d string as a calendar date at midnight UTC. "2026-02-30" is refused,
     * not rolled over into March.
     *
     * @throws InvalidArgumentException
     */
    private function parse(string $date): CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')
            : null;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a valid date: \"{$date}\". Use Y-m-d, e.g. 2026-11-20.");
        }

        return $parsed;
    }
}
