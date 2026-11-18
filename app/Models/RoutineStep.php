<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One product in a routine, with its own schedule (e.g. exfoliant Tue/Sat).
 *
 * @property int $id
 * @property int $user_id
 * @property int $routine_id
 * @property int $product_id
 * @property int $position
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([])]
class RoutineStep extends Model
{
    use BelongsToUser, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'product_id' => 'integer',
            'routine_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Routine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return MorphMany<ScheduleRule, $this>
     */
    public function scheduleRules(): MorphMany
    {
        return $this->morphMany(ScheduleRule::class, 'schedulable');
    }

    /**
     * The schedule version that applies today or next (not ended yet).
     */
    public function currentRule(): ?ScheduleRule
    {
        $today = now($this->user->timezone)->toDateString();

        return ScheduleRule::forUser($this->user_id)
            ->where('schedulable_type', 'routine_step')
            ->where('schedulable_id', $this->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')
            ->first();
    }
}
