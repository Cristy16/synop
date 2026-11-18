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
 * One dose time of a vitamin, with its own schedule. Taking it takes `units`
 * tablets off the product's `units_left` (D-047).
 *
 * @property int $id
 * @property int $user_id
 * @property int $product_id
 * @property string $dose_time
 * @property int $units
 * @property string|null $meal_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([])]
class VitaminDose extends Model
{
    use BelongsToUser, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'units' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'product_id' => 'integer',
        ];
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
            ->where('schedulable_type', 'vitamin_dose')
            ->where('schedulable_id', $this->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->orderBy('starts_on')
            ->first();
    }

    /**
     * "8:00 AM". MySQL returns 08:00:00, SQLite 08:00.
     */
    public function timeLabel(): string
    {
        return CarbonImmutable::createFromFormat('H:i', substr($this->dose_time, 0, 5))?->format('g:i A') ?? $this->dose_time;
    }
}
