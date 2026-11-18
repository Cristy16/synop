<?php

namespace App\Actions\Tasks;

use App\Actions\Schedules\CreateSchedule;
use App\Actions\Schedules\PauseSchedule;
use App\Actions\Schedules\ResumeSchedule;
use App\Actions\Schedules\StopSchedule;
use App\Actions\Schedules\UpdateSchedule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveTaskRepeat
{
    public function __construct(
        private UpdateTask $updateTask,
        private CreateSchedule $createSchedule,
        private UpdateSchedule $updateSchedule,
        private PauseSchedule $pauseSchedule,
        private ResumeSchedule $resumeSchedule,
        private StopSchedule $stopSchedule,
    ) {}

    /**
     * Save a task's fields and its repeat together, in one database transaction
     * (rule 12). A bad repeat changes nothing, not even the title.
     *
     * $repeat = null means "doesn't repeat": a repeating task stops from today
     * (its history stays) and becomes a normal one-off task again.
     *
     * @param  array<string, mixed>  $taskInput  title, notes, due_on, due_time, priority (see UpdateTask)
     * @param  array<string, mixed>|null  $repeat  frequency, interval | weekdays | day_of_month, ends_on, paused (bool)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Task $task, array $taskInput, ?array $repeat): Task
    {
        Gate::forUser($user)->authorize('update', $task);

        if ($repeat !== null && $task->source_type !== null) {
            throw ValidationException::withMessages([
                'frequency' => __('A task made by a :source can\'t repeat.', ['source' => $task->source_type]),
            ]);
        }

        return DB::transaction(function () use ($user, $task, $taskInput, $repeat) {
            // A repeating task's date is set by its schedule, so its own date is cleared (DATABASE §3.3).
            $this->updateTask->handle($user, $task, $repeat === null ? $taskInput : [...$taskInput, 'due_on' => null]);

            $rule = $task->currentRule();

            if ($repeat === null) {
                if ($rule !== null) {
                    $this->stopSchedule->handle($user, $rule);
                }

                return $task;
            }

            $pattern = [
                'frequency' => $repeat['frequency'] ?? null,
                'interval' => $repeat['interval'] ?? null,
                'weekdays' => $repeat['weekdays'] ?? null,
                'day_of_month' => $repeat['day_of_month'] ?? null,
                'ends_on' => $repeat['ends_on'] ?? null,
            ];

            $rule = $rule === null
                ? $this->createSchedule->handle($user, $task, [...$pattern, 'starts_on' => now($user->timezone)->toDateString()])
                : $this->updateSchedule->handle($user, $rule, $pattern);

            if (! empty($repeat['paused']) && ! $rule->isPaused()) {
                $this->pauseSchedule->handle($user, $rule);
            } elseif (empty($repeat['paused']) && $rule->isPaused()) {
                $this->resumeSchedule->handle($user, $rule);
            }

            return $task;
        });
    }
}
