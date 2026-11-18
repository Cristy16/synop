<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quiz, exam, project or assignment. Its linked task (source = activity) holds
 * the done state and can't be deleted on its own (DATABASE §3.6, D-012).
 *
 * @property int $id
 * @property int $user_id
 * @property int $course_id
 * @property ActivityType $type
 * @property string $title
 * @property CarbonImmutable $due_on
 * @property string|null $due_time
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class Activity extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'due_on' => 'date',
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
     * The linked task (one per activity).
     */
    public function task(): ?Task
    {
        return Task::forUser($this->user_id)
            ->where('source_type', 'activity')
            ->where('source_id', $this->id)
            ->first();
    }
}
