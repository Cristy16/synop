<?php

namespace App\Actions\Schedules;

use App\Enums\Frequency;
use App\Models\ScheduleRule;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation shared by CreateSchedule and UpdateSchedule (D-042 §10).
 */
class ScheduleInput
{
    public const MIN_INTERVAL = 2;

    public const MAX_INTERVAL = 365;

    /**
     * Exactly the field for the chosen frequency is filled; the others must be empty.
     *
     * @param  array<string, mixed>  $input  frequency, interval, weekdays, day_of_month, starts_on, ends_on
     * @return array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null, starts_on: string, ends_on: string|null}
     *
     * @throws ValidationException
     */
    public static function validate(array $input): array
    {
        $frequency = Frequency::tryFrom((string) ($input['frequency'] ?? ''));
        $needs = fn (Frequency $f) => $frequency === $f;

        $validated = Validator::make($input, [
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'interval' => $needs(Frequency::EveryNDays)
                ? ['required', 'integer', 'between:'.self::MIN_INTERVAL.','.self::MAX_INTERVAL]
                : ['prohibited'],
            'weekdays' => $needs(Frequency::Weekly) ? ['required', 'array', 'min:1', 'max:7'] : ['prohibited'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'day_of_month' => $needs(Frequency::Monthly) ? ['required', 'integer', 'between:1,31'] : ['prohibited'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ], [
            'interval.between' => __('Choose every :min to :max days. (Every 1 day is "Every day".)', ['min' => self::MIN_INTERVAL, 'max' => self::MAX_INTERVAL]),
            'weekdays.required' => __('Choose at least one day of the week.'),
        ])->validate();

        $weekdays = null;

        if ($needs(Frequency::Weekly)) {
            $weekdays = array_values(array_unique(array_map('intval', $validated['weekdays'])));
            sort($weekdays);
        }

        return [
            'frequency' => Frequency::from($validated['frequency']),
            'interval' => $needs(Frequency::EveryNDays) ? (int) $validated['interval'] : null,
            'weekdays' => $weekdays,
            'day_of_month' => $needs(Frequency::Monthly) ? (int) $validated['day_of_month'] : null,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
        ];
    }

    /**
     * An item's rules never overlap, so exactly one applies on any date (DATABASE §3.2).
     *
     * @throws ValidationException
     */
    public static function assertNoOverlap(User $user, string $type, int $id, string $startsOn, ?string $endsOn, ?int $ignoreRuleId = null): void
    {
        $overlaps = ScheduleRule::forUser($user)
            ->where('schedulable_type', $type)
            ->where('schedulable_id', $id)
            ->when($ignoreRuleId !== null, fn ($q) => $q->whereKeyNot($ignoreRuleId))
            ->when($endsOn !== null, fn ($q) => $q->whereDate('starts_on', '<=', $endsOn))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'starts_on' => __('These dates overlap another schedule for this item.'),
            ]);
        }
    }

    /**
     * Copy validated values onto a rule (not saved).
     *
     * @param  array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null, starts_on: string, ends_on: string|null}  $values
     */
    public static function fill(ScheduleRule $rule, array $values): void
    {
        $rule->frequency = $values['frequency'];
        $rule->interval = $values['interval'];
        $rule->weekdays = $values['weekdays'];
        $rule->day_of_month = $values['day_of_month'];
        $rule->setAttribute('starts_on', $values['starts_on']);
        $rule->setAttribute('ends_on', $values['ends_on']);
    }
}
