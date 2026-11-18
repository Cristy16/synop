<?php

namespace App\Services;

use App\Enums\DebtStatus;
use App\Enums\Frequency;
use App\Models\Debt;
use App\Models\ScheduleRule;
use App\Support\InstallmentDue;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The maths of installment debts (DATABASE §3.4, D-010, D-044). Read-only.
 *
 * A plan is: amount per payment (`installment_cents`) + the debt's schedule rule(s).
 * - Number of payments = principal ÷ installment, rounded up (₱3,000 at ₱700 → 5).
 * - Payment dates are the rule's occurrences, counted across all its versions in order.
 * - Next due = the first payment date whose running total is more than what's been paid.
 *   Late payments stay due; partial payments leave the same date due for the rest.
 * - Amount due on it = what's needed to catch up to that date, capped at what remains.
 */
class InstallmentPlan
{
    public const MAX_PAYMENTS = 120;

    public function __construct(private ScheduleEngine $engine) {}

    public function paymentCount(Money $principal, Money $installment): int
    {
        $per = max(1, $installment->cents());

        return intdiv($principal->cents() + $per - 1, $per);
    }

    /**
     * The debt's schedule versions, oldest first.
     *
     * @return Collection<int, ScheduleRule>
     */
    public function rulesOf(Debt $debt): Collection
    {
        return ScheduleRule::forUser($debt->user_id)
            ->where('schedulable_type', 'debt')
            ->where('schedulable_id', $debt->id)
            ->orderBy('starts_on')
            ->get();
    }

    /**
     * The next installment, or null when the debt is settled or has no plan.
     *
     * @param  Collection<int, ScheduleRule>|null  $rules  pass them in to save a query
     */
    public function nextDue(Debt $debt, ?Collection $rules = null): ?InstallmentDue
    {
        if ($debt->installment_cents === null || $debt->status === DebtStatus::Settled) {
            return null;
        }

        $per = $debt->installment_cents->cents();
        $paid = $debt->paid()->cents();
        $remaining = $debt->remaining();

        if ($per <= 0 || $remaining->isZero() || $remaining->isNegative()) {
            return null;
        }

        $count = $this->paymentCount($debt->principal_cents, $debt->installment_cents);
        $k = min(intdiv($paid, $per) + 1, $count);
        $dates = $this->dates($rules ?? $this->rulesOf($debt), $k);

        if ($dates === []) {
            return null;
        }

        // Every planned date has passed: everything left is due on the last one.
        if (count($dates) < $k) {
            return new InstallmentDue($dates[count($dates) - 1], $remaining, count($dates), $count);
        }

        $catchUp = Money::fromCents($k * $per - $paid);
        $amount = $catchUp->cents() < $remaining->cents() ? $catchUp : $remaining;

        return new InstallmentDue($dates[$k - 1], $amount, $k, $count);
    }

    /**
     * "6 payments, last on May 15": the plan a new set of inputs would make.
     *
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $pattern
     * @return array{count: int, last: string|null}
     */
    public function preview(Money $principal, Money $installment, array $pattern, string $firstDueOn): array
    {
        $count = $this->paymentCount($principal, $installment);

        return ['count' => $count, 'last' => $this->nthDate($this->ruleFor($pattern, $firstDueOn), $count)];
    }

    /**
     * The end date for a (new or current) version so that, together with the payment
     * dates of earlier versions, the plan has exactly $total payments.
     *
     * @param  Collection<int, ScheduleRule>  $earlier  versions before this one (each has an end date)
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $pattern
     */
    public function endFor(Collection $earlier, array $pattern, string $startsOn, int $total): ?string
    {
        $before = count($this->dates($earlier, $total));

        return $this->nthDate($this->ruleFor($pattern, $startsOn), max(1, $total - $before));
    }

    /**
     * Payment dates across the versions, oldest first, at most $limit of them.
     *
     * @param  Collection<int, ScheduleRule>  $rules
     * @return list<string>
     */
    public function dates(Collection $rules, int $limit): array
    {
        $dates = [];

        foreach ($rules->sortBy(fn (ScheduleRule $r) => $r->starts_on->toDateString()) as $rule) {
            $from = $rule->starts_on->toDateString();

            while (count($dates) < $limit && ($next = $this->engine->nextOnOrAfter($rule, $from)) !== null) {
                $dates[] = $next;
                // Calendar-day maths in UTC, like the engine, so daylight saving can't interfere.
                $from = CarbonImmutable::createFromFormat('!Y-m-d', $next, 'UTC')?->addDay()->format('Y-m-d') ?? $next;
            }
        }

        return $dates;
    }

    /**
     * The $n-th occurrence (1 = the first) of an open-ended rule.
     */
    public function nthDate(ScheduleRule $rule, int $n): ?string
    {
        $open = (clone $rule)->forceFill(['ends_on' => null, 'paused_at' => null]);

        $dates = $this->dates(new Collection([$open]), $n);

        return $dates[$n - 1] ?? null;
    }

    /**
     * An unsaved rule, just for the maths.
     *
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $pattern
     */
    private function ruleFor(array $pattern, string $startsOn): ScheduleRule
    {
        return (new ScheduleRule)->forceFill([
            'frequency' => $pattern['frequency'],
            'interval' => $pattern['interval'],
            'weekdays' => $pattern['weekdays'],
            'day_of_month' => $pattern['day_of_month'],
            'starts_on' => $startsOn,
            'ends_on' => null,
            'paused_at' => null,
        ]);
    }
}
