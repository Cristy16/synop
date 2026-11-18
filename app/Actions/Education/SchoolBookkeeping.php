<?php

namespace App\Actions\Education;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\ScheduleRule;
use App\Models\Semester;
use App\Models\Task;

/**
 * Keeps what hangs off school records in step: an activity's linked task, and
 * cleaning up tasks and schedules (polymorphic, so no foreign keys) before a
 * course or semester is deleted (DATABASE §1.5). Internal helper for the
 * Education Actions; callers wrap it in DB::transaction() (rule 12).
 */
class SchoolBookkeeping
{
    /**
     * One task per activity: "Quiz: Chapter 3 quiz (CS 101)", due with the activity.
     * The done state stays on the task (DATABASE §3.6).
     */
    public function syncTask(Activity $activity, Course $course): void
    {
        $task = $activity->task();

        if ($task === null) {
            $task = new Task;
            $task->user_id = $activity->user_id;
            $task->source_type = 'activity';
            $task->source_id = $activity->id;
        }

        $task->title = self::taskTitle($activity->type, $activity->title, $course);
        $task->setAttribute('due_on', $activity->due_on->toDateString());
        $task->due_time = $activity->due_time;
        $task->save();
    }

    public static function taskTitle(ActivityType $type, string $title, Course $course): string
    {
        return __(':type: :title (:course)', ['type' => __($type->label()), 'title' => $title, 'course' => $course->shortName()]);
    }

    public function deleteActivity(Activity $activity): void
    {
        $activity->task()?->delete();
        $activity->delete();
    }

    public function deleteSession(ClassSession $session): void
    {
        ScheduleRule::forUser($session->user_id)
            ->where('schedulable_type', 'class_session')
            ->where('schedulable_id', $session->id)
            ->delete();

        $session->delete();
    }

    /**
     * A course with its class times (and their schedules) and activities (and their tasks).
     */
    public function deleteCourse(Course $course): void
    {
        foreach (Activity::forUser($course->user_id)->where('course_id', $course->id)->get() as $activity) {
            $this->deleteActivity($activity);
        }

        foreach (ClassSession::forUser($course->user_id)->where('course_id', $course->id)->get() as $session) {
            $this->deleteSession($session);
        }

        $course->delete();
    }

    public function deleteSemester(Semester $semester): void
    {
        foreach (Course::forUser($semester->user_id)->where('semester_id', $semester->id)->get() as $course) {
            $this->deleteCourse($course);
        }

        $semester->delete();
    }
}
