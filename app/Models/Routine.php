<?php

namespace App\Models;

use App\Enums\RoutinePeriod;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The morning or night routine. Exactly one of each per user (D-017).
 *
 * @property int $id
 * @property int $user_id
 * @property RoutinePeriod $period
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class Routine extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => RoutinePeriod::class,
        ];
    }

    /**
     * Steps in order (removed ones are hidden by the soft delete).
     *
     * @return HasMany<RoutineStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RoutineStep::class)->orderBy('position')->orderBy('id');
    }
}
