<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Answers "which classes do I have on this date?" Read-only (D-048).
 *
 * Classes from every semester that isn't archived, on the days their schedule
 * falls on (within the semester's dates), earliest first.
 */
class ClassSchedule
{
    public function __construct(private ScheduledItems $scheduledItems) {}

    /**
     * @return list<ClassSession> each with `course` and `course.semester` loaded
     */
    public function on(User $user, string $date): array
    {
        $sessions = new Collection;

        foreach ($this->scheduledItems->on($user, $date, 'class_session') as $item) {
            if ($item->item instanceof ClassSession) {
                $sessions->push($item->item);
            }
        }

        // A session's course and semester always belong to the session's owner.
        $sessions->load([
            'course' => fn ($q) => $q->withoutGlobalScope('user'),
            'course.semester' => fn ($q) => $q->withoutGlobalScope('user'),
        ]);

        return array_values($sessions
            ->filter(fn (ClassSession $s) => $s->course?->semester !== null && ! $s->course->semester->isArchived())
            ->sortBy(fn (ClassSession $s) => substr($s->start_time, 0, 5).'|'.$s->id)
            ->all());
    }
}
