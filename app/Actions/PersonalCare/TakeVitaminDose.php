<?php

namespace App\Actions\PersonalCare;

use App\Actions\Schedules\MarkOccurrence;
use App\Actions\Schedules\UnmarkOccurrence;
use App\Enums\ScheduleLogStatus;
use App\Models\Product;
use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Models\VitaminDose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TakeVitaminDose
{
    public function __construct(
        private MarkOccurrence $mark,
        private UnmarkOccurrence $unmark,
        private LowStockReminder $reminder,
    ) {}

    /**
     * Mark today's dose done or skipped, or clear it ($status = null), and keep
     * the tablets left in step (DATABASE §3.2, D-047):
     *
     * - becomes done: its units are taken off (never below 0);
     * - stops being done (untick, or done → skipped): they're put back;
     * - skipped: no change. Stock not tracked (units_left empty): no change.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, ScheduleRule $rule, ?ScheduleLogStatus $status): void
    {
        Gate::forUser($user)->authorize('update', $rule);

        $dose = VitaminDose::forUser($user)->withTrashed()->findOrFail(
            $rule->schedulable_type === 'vitamin_dose' ? $rule->schedulable_id : 0
        );
        $today = now($user->timezone)->toDateString();

        DB::transaction(function () use ($user, $rule, $status, $dose, $today) {
            $before = ScheduleLog::forUser($user)->where('schedule_rule_id', $rule->id)->whereDate('occurs_on', $today)->first()?->status;

            $status === null
                ? $this->unmark->handle($user, $rule, $today)
                : $this->mark->handle($user, $rule, $today, $status);

            $wasDone = $before === ScheduleLogStatus::Done;
            $isDone = $status === ScheduleLogStatus::Done;

            if ($wasDone === $isDone) {
                return;
            }

            // Lock the product so two doses at once can't both read the old count.
            $product = Product::forUser($user)->withTrashed()->lockForUpdate()->findOrFail($dose->product_id);

            if ($product->units_left === null) {
                return;
            }

            $wasLow = LowStockReminder::isProductLow($product);
            $product->units_left = max(0, $product->units_left + ($isDone ? -$dose->units : $dose->units));
            $product->save();

            $this->reminder->sync($product, $wasLow);
        });
    }
}
