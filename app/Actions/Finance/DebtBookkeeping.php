<?php

namespace App\Actions\Finance;

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\RemoveSchedules;
use App\Actions\Schedules\UpdateSchedule;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Enums\Frequency;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\ScheduleRule;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\InstallmentPlan;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps everything that hangs off a debt in step with it: the principal
 * transaction, its status, its installment schedule, its linked task, and
 * hiding/restoring them together.
 *
 * Internal helper for the debt Actions. Callers wrap it in DB::transaction()
 * (rule 12). It is the only code that touches a debt's task or schedule
 * (D-012, D-040, D-044). Schedules still change only through the schedule Actions.
 */
class DebtBookkeeping
{
    public function __construct(
        private InstallmentPlan $plans,
        private CreateSchedule $createSchedule,
        private UpdateSchedule $updateSchedule,
        private RemoveSchedules $removeSchedules,
    ) {}

    /**
     * Make the debt's schedule match its plan. Call after saving the debt.
     *
     * - One payment: any installment schedule is removed (the history is in the payments).
     * - Installments, no schedule yet (or only ended ones): a new one from the first due date.
     * - Installments, first due date not reached yet: the schedule is edited in place.
     * - Installments already running: same pattern -> only the end date moves; a new
     *   pattern -> the old version ends yesterday and a new one starts today (D-013).
     *   The end date always makes the total number of payment dates right.
     *
     * @param  array{installment: Money, pattern: array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}, first_due_on: string, count: int, last: string}|null  $plan
     */
    public function syncPlan(Debt $debt, ?array $plan): void
    {
        $user = $debt->user;
        $rules = $this->plans->rulesOf($debt);

        if ($plan === null) {
            if ($rules->isNotEmpty()) {
                $this->removeSchedules->handle($user, $debt);
            }

            return;
        }

        $today = now($user->timezone)->toDateString();
        $yesterday = now($user->timezone)->subDay()->toDateString();
        $current = $rules->last();

        if ($current === null || ($current->ends_on !== null && $current->ends_on->toDateString() < $today)) {
            if ($rules->isNotEmpty()) {
                $this->removeSchedules->handle($user, $debt);
            }

            $this->createSchedule->handle($user, $debt, [...$this->patternInput($plan['pattern']), 'starts_on' => $plan['first_due_on'], 'ends_on' => $plan['last']]);

            return;
        }

        if ($current->starts_on->toDateString() >= $today) {
            $this->updateSchedule->handle($user, $current, [...$this->patternInput($plan['pattern']), 'starts_on' => $plan['first_due_on'], 'ends_on' => $plan['last']]);

            return;
        }

        /** @var Collection<int, ScheduleRule> $earlier */
        $earlier = $rules->slice(0, -1)->values();

        if ($this->samePattern($current, $plan['pattern'])) {
            $end = $this->plans->endFor($earlier, $plan['pattern'], $current->starts_on->toDateString(), $plan['count']);

            // An end before yesterday would rewrite the past; the extra dates are never used anyway.
            if ($end !== null && $end >= $yesterday) {
                $this->updateSchedule->handle($user, $current, [...$this->patternInput($plan['pattern']), 'ends_on' => $end]);
            }

            return;
        }

        // A new pattern from today: count the dates the current version had up to yesterday.
        $endedCurrent = (clone $current)->forceFill(['ends_on' => $yesterday]);
        $end = $this->plans->endFor($earlier->push($endedCurrent), $plan['pattern'], $today, $plan['count']);

        $this->updateSchedule->handle($user, $current, [...$this->patternInput($plan['pattern']), 'ends_on' => $end]);
    }

    /**
     * Create, move or remove the principal transaction to match the debt.
     * With no account (a debt from before using the app) there is none.
     */
    public function syncPrincipal(Debt $debt): void
    {
        $transaction = $this->transactionsOf($debt->user_id, 'debt', $debt->id)->first();

        if ($debt->account_id === null) {
            $transaction?->delete();

            return;
        }

        $transaction ??= new Transaction;
        $transaction->user_id = $debt->user_id;
        $transaction->account_id = $debt->account_id;
        $transaction->to_account_id = null;
        $transaction->type = $debt->direction->principalType();
        $transaction->amount_cents = $debt->principal_cents;
        $transaction->category_id = null;
        $transaction->setAttribute('occurred_on', $debt->started_on->toDateString());
        $transaction->note = $debt->description;
        $transaction->source_type = 'debt';
        $transaction->source_id = $debt->id;
        $transaction->save();
    }

    /**
     * Record the one transaction a payment creates.
     */
    public function recordPaymentTransaction(Debt $debt, DebtPayment $payment): void
    {
        $transaction = new Transaction;
        $transaction->user_id = $debt->user_id;
        $transaction->account_id = $payment->account_id;
        $transaction->type = $debt->direction->paymentType();
        $transaction->amount_cents = $payment->amount_cents;
        $transaction->setAttribute('occurred_on', $payment->paid_on->toDateString());
        $transaction->note = $payment->note;
        $transaction->source_type = 'debt_payment';
        $transaction->source_id = $payment->id;
        $transaction->save();
    }

    /**
     * Settled when nothing remains, open otherwise. Then bring the task in line.
     */
    public function refresh(Debt $debt): void
    {
        $settled = $debt->remaining()->isZero();

        if ($settled && ! $debt->isSettled()) {
            $debt->status = DebtStatus::Settled;
            $debt->settled_at = now();
        } elseif (! $settled && $debt->isSettled()) {
            $debt->status = DebtStatus::Open;
            $debt->settled_at = null;
        }

        $debt->save();

        $this->syncTask($debt);
    }

    /**
     * One task per debt with a due date: "Collect ₱500.00 from Ben" / "Pay Ana ₱500.00".
     * The amount is what remains. Completed when settled; removed when the debt has no
     * due date or is deleted.
     */
    public function syncTask(Debt $debt): void
    {
        $task = Task::forUser($debt->user_id)
            ->where('source_type', 'debt')
            ->where('source_id', $debt->id)
            ->first();

        // One-off debts use their due date; installment debts their next due date (D-044).
        $next = $debt->installment_cents === null ? null : $this->plans->nextDue($debt);
        $dueOn = $debt->installment_cents === null ? $debt->due_on?->toDateString() : $next?->date;

        if ($debt->trashed() || $dueOn === null) {
            // A settled installment debt has no next date but keeps its completed task.
            if ($debt->trashed() || $debt->installment_cents === null || ! $debt->isSettled() || $task === null) {
                $task?->delete();

                return;
            }

            $dueOn = $task->due_on?->toDateString();
        }

        if ($task === null) {
            $task = new Task;
            $task->user_id = $debt->user_id;
            $task->source_type = 'debt';
            $task->source_id = $debt->id;
        }

        if (! $debt->isSettled() || ! $task->exists) {
            $task->title = $this->taskTitle($debt, $next?->amount);
        }

        $task->setAttribute('due_on', $dueOn);
        $task->completed_at = $debt->isSettled() ? ($task->completed_at ?? $debt->settled_at ?? now()) : null;
        $task->save();
    }

    /**
     * Hide a debt with its payments and all their transactions, stamped with the same
     * moment so they can be restored together (and only together).
     */
    public function hide(Debt $debt, CarbonImmutable $at): void
    {
        $paymentIds = DebtPayment::forUser($debt->user_id)->where('debt_id', $debt->id)->pluck('id');

        $this->transactionsOf($debt->user_id, 'debt_payment', $paymentIds->all())->update(['deleted_at' => $at]);
        $this->transactionsOf($debt->user_id, 'debt', $debt->id)->update(['deleted_at' => $at]);
        DebtPayment::forUser($debt->user_id)->whereKey($paymentIds)->update(['deleted_at' => $at]);
        Debt::forUser($debt->user_id)->whereKey($debt->id)->update(['deleted_at' => $at]);

        $debt->deleted_at = $at;
        $this->syncTask($debt);
    }

    /**
     * Undo hide(): bring back exactly the rows hidden at the same moment as the debt.
     * Payments deleted on their own earlier stay deleted.
     */
    public function unhide(Debt $debt): void
    {
        $at = $debt->getRawOriginal('deleted_at');

        $paymentIds = DebtPayment::forUser($debt->user_id)->onlyTrashed()
            ->where('debt_id', $debt->id)
            ->where('deleted_at', $at)
            ->pluck('id');

        DebtPayment::forUser($debt->user_id)->onlyTrashed()->whereKey($paymentIds)->update(['deleted_at' => null]);
        $this->transactionsOf($debt->user_id, 'debt_payment', $paymentIds->all(), onlyTrashed: true)->where('deleted_at', $at)->update(['deleted_at' => null]);
        $this->transactionsOf($debt->user_id, 'debt', $debt->id, onlyTrashed: true)->where('deleted_at', $at)->update(['deleted_at' => null]);

        $debt->restore();
        $this->refresh($debt);
    }

    /**
     * @param  Money|null  $due  for installment debts: the amount due on the next date
     */
    private function taskTitle(Debt $debt, ?Money $due = null): string
    {
        // What's due next (installments) or left to pay; for a settled debt, the original amount.
        $amount = ($debt->isSettled() ? $debt->principal_cents : ($due ?? $debt->remaining()))->format($debt->user->currency);
        $name = Contact::forUser($debt->user_id)->withTrashed()->findOrFail($debt->contact_id)->name;

        return $debt->direction === DebtDirection::OwedToMe
            ? __('Collect :amount from :name', ['amount' => $amount, 'name' => $name])
            : __('Pay :name :amount', ['name' => $name, 'amount' => $amount]);
    }

    /**
     * The transactions created by these source rows (a debt, or debt payments).
     *
     * @param  int|array<int, int>  $ids
     * @return Builder<Transaction>
     */
    private function transactionsOf(int $userId, string $sourceType, int|array $ids, bool $onlyTrashed = false): Builder
    {
        $query = Transaction::forUser($userId)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', (array) $ids);

        return $onlyTrashed ? $query->onlyTrashed() : $query;
    }

    /**
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $pattern
     * @return array<string, mixed>
     */
    private function patternInput(array $pattern): array
    {
        return [
            'frequency' => $pattern['frequency']->value,
            'interval' => $pattern['interval'],
            'weekdays' => $pattern['weekdays'],
            'day_of_month' => $pattern['day_of_month'],
        ];
    }

    /**
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}  $pattern
     */
    private function samePattern(ScheduleRule $rule, array $pattern): bool
    {
        $weekdays = $rule->weekdays === null ? null : array_map('intval', $rule->weekdays);

        return $rule->frequency === $pattern['frequency']
            && $rule->interval === $pattern['interval']
            && $weekdays === $pattern['weekdays']
            && $rule->day_of_month === $pattern['day_of_month'];
    }
}
