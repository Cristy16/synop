<?php

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\Money;
use App\Support\SafeToSpendBreakdown;
use Carbon\CarbonImmutable;

/**
 * Answers "how much can I spend without coming up short?" Pure PHP, never the AI
 * (D-027, ARCHITECTURE §7). Read-only.
 *
 *   safe = total balance
 *        − what I owe that falls due before the chosen date (capped at what remains)
 *        − my safety buffer
 *
 * "Scheduled expenses" were dropped for the MVP (D-008). The date defaults to the
 * user's next payday, or the end of the month when no paydays are set (D-050).
 */
class SafeToSpend
{
    public function __construct(
        private AccountBalances $balances,
        private InstallmentPlan $plans,
    ) {}

    /**
     * @param  string|null  $until  Y-m-d; debts due **before** it count. Null = next payday.
     */
    public function for(User $user, ?string $until = null): SafeToSpendBreakdown
    {
        $until ??= $this->nextPayday($user);
        $owed = [];
        $owedTotal = Money::zero();

        $debts = Debt::forUser($user)
            ->where('direction', DebtDirection::IOwe->value)
            ->where('status', DebtStatus::Open->value)
            ->orderBy('id')
            ->get();

        foreach ($debts as $debt) {
            [$amount, $dueOn] = $this->dueBefore($debt, $until);

            if ($amount !== null && ! $amount->isZero() && ! $amount->isNegative()) {
                $owed[] = ['debt' => $debt, 'amount' => $amount, 'due_on' => $dueOn];
                $owedTotal = $owedTotal->add($amount);
            }
        }

        usort($owed, fn (array $a, array $b) => [$a['due_on'], $a['debt']->id] <=> [$b['due_on'], $b['debt']->id]);

        $balance = $this->balances->total($user);
        $buffer = $user->safety_buffer_cents;

        return new SafeToSpendBreakdown(
            until: $until,
            balance: $balance,
            owed: $owed,
            owedTotal: $owedTotal,
            buffer: $buffer,
            safe: $balance->subtract($owedTotal)->subtract($buffer),
        );
    }

    /**
     * The first payday after today (days 29–31 fall on the last day of shorter
     * months); with no paydays set, the end of this month (or of next month on its
     * last day).
     */
    public function nextPayday(User $user): string
    {
        $today = now($user->timezone)->toImmutable()->startOfDay();
        $paydays = $user->paydays ?? [];

        if ($paydays === []) {
            $end = $today->endOfMonth()->startOfDay();

            return ($end->equalTo($today) ? $today->addMonthNoOverflow()->endOfMonth() : $end)->toDateString();
        }

        foreach ([0, 1] as $monthsAhead) {
            $month = $today->startOfMonth()->addMonthsNoOverflow($monthsAhead);

            foreach ($paydays as $day) {
                $date = $month->setDay(min((int) $day, $month->daysInMonth));

                if ($date->greaterThan($today)) {
                    return $date->toDateString();
                }
            }
        }

        // Unreachable with valid paydays (1–31), but keep a sane answer.
        return $today->endOfMonth()->toDateString();
    }

    /**
     * What this debt asks for before $until, and the earliest date it's due.
     *
     * @return array{0: Money|null, 1: string}
     */
    private function dueBefore(Debt $debt, string $until): array
    {
        $remaining = $debt->remaining();

        // One-off debt: all that remains, if it's due (or overdue) before the date.
        if ($debt->installment_cents === null) {
            $dueOn = $debt->due_on?->toDateString();

            return $dueOn !== null && $dueOn < $until ? [$remaining, $dueOn] : [null, ''];
        }

        // Installments: the installments falling before the date, minus what's paid, capped at what remains.
        $per = $debt->installment_cents->cents();
        $rules = ScheduleRule::forUser($debt->user_id)
            ->where('schedulable_type', 'debt')
            ->where('schedulable_id', $debt->id)
            ->orderBy('starts_on')
            ->get();
        $dates = array_values(array_filter(
            $this->plans->dates($rules, $this->plans->paymentCount($debt->principal_cents, $debt->installment_cents)),
            fn (string $d) => $d < $until,
        ));

        if ($dates === []) {
            return [null, ''];
        }

        $paid = $debt->paid()->cents();
        $due = count($dates) * $per - $paid;
        $amount = Money::fromCents(max(0, min($due, $remaining->cents())));
        // The first payment date not yet covered by what's been paid.
        $firstUnpaid = $dates[min(intdiv($paid, max(1, $per)), count($dates) - 1)];

        return [$amount, $firstUnpaid];
    }

    /**
     * Is this a real Y-m-d date? (For the page's date picker.)
     */
    public static function isValidDate(string $date): bool
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date;
    }
}
