<?php

namespace App\Services;

use App\Enums\ConflictScope;
use App\Enums\RoutinePeriod;
use App\Models\ConflictRule;
use App\Models\IngredientTag;
use App\Models\Product;
use App\Models\RoutineStep;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\Clash;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Answers "which of my conflict rules are broken?" Read-only (D-018, D-046).
 *
 * A rule names two ingredient groups and a scope: the same routine (AM or PM),
 * or the same day. It is broken when, among the steps scheduled that day, one
 * step's product has the first group and a **different** step's product has the
 * second. One product that contains both groups doesn't count on its own.
 */
class ConflictChecker
{
    /** How far ahead the "when saving a step" check looks. */
    public const LOOK_AHEAD_DAYS = 28;

    public function __construct(
        private ScheduledItems $scheduledItems,
        private ScheduleEngine $engine,
    ) {}

    /**
     * Rules broken on a date by the steps scheduled that day (Today's warnings).
     *
     * @return list<Clash>
     */
    public function onDate(User $user, string $date): array
    {
        $steps = new Collection;

        foreach ($this->scheduledItems->on($user, $date, 'routine_step') as $item) {
            if ($item->item instanceof RoutineStep) {
                $steps->push($item->item);
            }
        }

        return $this->check($this->entries($steps), $this->rules($user));
    }

    /**
     * Rules this step would break over the next 4 weeks, each with its dates
     * (the warning after saving a step).
     *
     * @return list<array{clash: Clash, dates: list<string>}>
     */
    public function upcomingFor(User $user, RoutineStep $step, int $days = self::LOOK_AHEAD_DAYS): array
    {
        $today = now($user->timezone)->toImmutable()->startOfDay();

        $schedules = ScheduleRule::forUser($user)
            ->where('schedulable_type', 'routine_step')
            ->whereNull('paused_at')
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today->toDateString()))
            ->with(['schedulable' => fn (MorphTo $m) => $m->withoutGlobalScope('user')])
            ->get()
            ->filter(fn (ScheduleRule $r) => $r->schedulable instanceof RoutineStep);

        $steps = new Collection;

        foreach ($schedules as $schedule) {
            if ($schedule->schedulable instanceof RoutineStep && ! $steps->contains('id', $schedule->schedulable->id)) {
                $steps->push($schedule->schedulable);
            }
        }

        $entries = collect($this->entries($steps))->keyBy(fn (array $e) => $e['step']->id);
        $rules = $this->rules($user);
        $found = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $today->addDays($i)->format('Y-m-d');
            $onDate = $schedules->filter(fn (ScheduleRule $r) => $this->engine->occursOn($r, $date))->pluck('schedulable_id')->unique();

            if (! $onDate->contains($step->id)) {
                continue;
            }

            $dayEntries = array_values($entries->only($onDate->all())->all());

            foreach ($this->check($dayEntries, $rules, involving: $step->id) as $clash) {
                $key = $clash->rule->id.'-'.($clash->period->value ?? 'day');
                $found[$key] ??= ['clash' => $clash, 'dates' => []];
                $found[$key]['dates'][] = $date;
            }
        }

        return array_values($found);
    }

    /**
     * @param  list<array{step: RoutineStep, period: RoutinePeriod, product: Product, tags: list<int>}>  $entries
     * @param  Collection<int, ConflictRule>  $rules
     * @return list<Clash>
     */
    private function check(array $entries, Collection $rules, ?int $involving = null): array
    {
        $clashes = [];

        foreach ($rules as $rule) {
            $units = $rule->scope === ConflictScope::SameRoutine
                ? collect($entries)->groupBy(fn (array $e) => $e['period']->value)->all()
                : ['day' => collect($entries)];

            foreach ($units as $unit) {
                $a = $unit->filter(fn (array $e) => in_array($rule->tag_a_id, $e['tags'], true));
                $b = $unit->filter(fn (array $e) => in_array($rule->tag_b_id, $e['tags'], true));
                $productsA = [];
                $productsB = [];

                foreach ($a as $x) {
                    foreach ($b as $y) {
                        $twoSteps = $x['step']->id !== $y['step']->id;
                        $relevant = $involving === null || $x['step']->id === $involving || $y['step']->id === $involving;

                        if ($twoSteps && $relevant) {
                            $productsA[$x['product']->id] = $x['product'];
                            $productsB[$y['product']->id] = $y['product'];
                        }
                    }
                }

                if ($productsA !== []) {
                    $first = $unit->first();
                    $period = $rule->scope === ConflictScope::SameRoutine && $first !== null ? $first['period'] : null;
                    $clashes[] = new Clash($rule, array_values($productsA), array_values($productsB), $period);
                }
            }
        }

        return $clashes;
    }

    /**
     * Each step with its routine's period, its product and the product's tag ids.
     *
     * @param  Collection<int, RoutineStep>  $steps
     * @return list<array{step: RoutineStep, period: RoutinePeriod, product: Product, tags: list<int>}>
     */
    private function entries(Collection $steps): array
    {
        // A step's routine, product and tags always belong to the step's owner.
        $steps->load([
            'routine' => fn ($q) => $q->withoutGlobalScope('user'),
            'product' => fn ($q) => $q->withoutGlobalScope('user'),
            'product.tags' => fn ($q) => $q->withoutGlobalScope('user'),
        ]);

        $entries = [];

        foreach ($steps as $step) {
            if ($step->routine === null || $step->product === null) {
                continue;
            }

            $entries[] = [
                'step' => $step,
                'period' => $step->routine->period,
                'product' => $step->product,
                'tags' => array_values($step->product->tags->map(fn (IngredientTag $t) => $t->id)->all()),
            ];
        }

        return $entries;
    }

    /**
     * @return Collection<int, ConflictRule>
     */
    private function rules(User $user): Collection
    {
        return ConflictRule::forUser($user)
            ->with(['tagA' => fn ($q) => $q->withoutGlobalScope('user'), 'tagB' => fn ($q) => $q->withoutGlobalScope('user')])
            ->orderBy('id')
            ->get();
    }
}
