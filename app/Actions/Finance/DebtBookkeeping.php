<?php

namespace App\Actions\Finance;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Task;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps everything that hangs off a debt in step with it: the principal
 * transaction, its status, its linked task, and hiding/restoring them together.
 *
 * Internal helper for the debt Actions. Callers wrap it in DB::transaction()
 * (rule 12). It is the only code that touches a debt's task (D-012, D-040).
 */
class DebtBookkeeping
{
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

        if ($debt->trashed() || $debt->due_on === null) {
            $task?->delete();

            return;
        }

        if ($task === null) {
            $task = new Task;
            $task->user_id = $debt->user_id;
            $task->source_type = 'debt';
            $task->source_id = $debt->id;
        }

        if (! $debt->isSettled() || ! $task->exists) {
            $task->title = $this->taskTitle($debt);
        }

        $task->setAttribute('due_on', $debt->due_on->toDateString());
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

    private function taskTitle(Debt $debt): string
    {
        // What's left to pay; for a settled debt (e.g. restored), the original amount.
        $amount = ($debt->isSettled() ? $debt->principal_cents : $debt->remaining())->format($debt->user->currency);
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
}
