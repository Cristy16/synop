<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A school term. One is active at a time (the one School opens on); archived
 * ones are hidden from Today (D-048).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property bool $is_active
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class Semester extends Model
{
    use BelongsToUser;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class)->orderBy('code')->orderBy('title');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The semester School shows: the one asked for, else the active one, else the
     * newest not archived, else the newest.
     */
    public static function pick(?int $id): ?self
    {
        return ($id !== null ? self::query()->find($id) : null)
            ?? self::query()->where('is_active', true)->first()
            ?? self::query()->whereNull('archived_at')->latest('starts_on')->first()
            ?? self::query()->latest('starts_on')->first();
    }
}
