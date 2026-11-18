<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $semester_id
 * @property string|null $code
 * @property string $title
 * @property string|null $instructor
 * @property string|null $room
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'title', 'instructor', 'room'])]
class Course extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semester_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Semester, $this>
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * @return HasMany<ClassSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('start_time')->orderBy('id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderBy('due_on')->orderBy('id');
    }

    /**
     * "CS 101 Intro to Programming", or just the title.
     */
    public function displayName(): string
    {
        return trim(($this->code ?? '').' '.$this->title);
    }

    /**
     * "CS 101", or the title when there's no code.
     */
    public function shortName(): string
    {
        return $this->code ?? $this->title;
    }
}
