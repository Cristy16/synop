<?php

namespace App\Services;

use App\Models\ScheduleLog;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\ScheduledItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Answers "which repeating items happen on this date?" for any kind of item
 * (tasks now; routine steps, vitamins and classes later). Read-only.
 *
 * A fixed handful of queries however many items there are (D-042 §5):
 * the active rules, one per item type for the items, and that day's logs.
 * The engine then decides in memory.
 */
class ScheduledItems
{
    public function __construct(private ScheduleEngine $engine) {}

    /**
     * @param  string  $date  the user's local date, Y-m-d
     * @param  string|null  $type  a morph alias such as 'task', or null for every type
     * @return Collection<int, ScheduledItem>
     */
    public function on(User $user, string $date, ?string $type = null): Collection
    {
        $rules = ScheduleRule::forUser($user)
            ->whereNull('paused_at')
            ->whereDate('starts_on', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->when($type !== null, fn (Builder $q) => $q->where('schedulable_type', $type))
            // An item always belongs to its rule's owner (CreateSchedule checks), so the owner
            // scope isn't needed for loading it; dropping it keeps this right outside a request.
            ->with(['schedulable' => fn (MorphTo $morph) => $morph->withoutGlobalScope('user')])
            ->orderBy('id')
            ->get()
            ->filter(fn (ScheduleRule $rule) => $rule->schedulable !== null && $this->engine->occursOn($rule, $date));

        if ($rules->isEmpty()) {
            return collect();
        }

        $logs = ScheduleLog::forUser($user)
            ->whereIn('schedule_rule_id', $rules->modelKeys())
            ->whereDate('occurs_on', $date)
            ->get()
            ->keyBy('schedule_rule_id');

        return $rules
            ->map(fn (ScheduleRule $rule) => new ScheduledItem($rule, $rule->schedulable, $date, $logs->get($rule->id)))
            ->values()
            ->toBase();
    }
}
