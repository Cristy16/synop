<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One class time slot ("MWF 8:00–10:00 · Lecture"), with its own schedule rule
 * running over the semester's dates.
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property string $start_time
 * @property string $end_time
 * @property string|null $room
 * @property string|null $label
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class ClassSession extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The newest version of this session's schedule.
     */
    public function latestRule(): ?ScheduleRule
    {
        return ScheduleRule::forUser($this->user_id)
            ->where('schedulable_type', 'class_session')
            ->where('schedulable_id', $this->id)
            ->latest('starts_on')
            ->first();
    }

    /**
     * "8:00–10:00 AM", or "11:00 AM–1:00 PM" across noon.
     */
    public function timeRange(): string
    {
        $start = CarbonImmutable::createFromFormat('H:i', substr($this->start_time, 0, 5));
        $end = CarbonImmutable::createFromFormat('H:i', substr($this->end_time, 0, 5));

        if ($start === null || $end === null) {
            return $this->start_time.'–'.$this->end_time;
        }

        return $start->format('A') === $end->format('A')
            ? $start->format('g:i').'–'.$end->format('g:i A')
            : $start->format('g:i A').'–'.$end->format('g:i A');
    }

    /**
     * The session's own room, or the course's.
     */
    public function roomOr(?Course $course): ?string
    {
        return $this->room ?? $course?->room;
    }
}
