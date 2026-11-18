<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A to-do item. Either typed in by the user, or created by another module
 * (an activity, a debt, a low-stock product), which is then its `source`.
 *
 * `user_id`, `source_*` and `completed_at` are not fillable: they are only
 * set by the Task Actions (app/Actions/Tasks).
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $notes
 * @property CarbonImmutable|null $due_on
 * @property string|null $due_time
 * @property TaskPriority $priority
 * @property CarbonImmutable|null $completed_at
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title', 'notes', 'due_on', 'due_time', 'priority'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Sources whose task can't be deleted directly: delete the source instead (D-012).
     */
    public const PROTECTED_SOURCES = ['activity', 'debt'];

    /**
     * Polymorphic children have no foreign key, so they are cleaned up here (DATABASE §1.5).
     * Their logs go with them through schedule_logs' cascading foreign key.
     */
    protected static function booted(): void
    {
        static::deleting(function (Task $task): void {
            ScheduleRule::forUser($task->user_id)
                ->where('schedulable_type', 'task')
                ->where('schedulable_id', $task->id)
                ->delete();
        });
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'normal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'priority' => TaskPriority::class,
            'source_id' => 'integer',
        ];
    }

    /**
     * The record that created this task, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isSourceProtected(): bool
    {
        return in_array($this->source_type, self::PROTECTED_SOURCES, true);
    }
}
