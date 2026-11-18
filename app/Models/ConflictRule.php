<?php

namespace App\Models;

use App\Enums\ConflictScope;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "AHA — not with Retinoid in the same routine." The user's own note, never advice (D-018).
 *
 * @property int $id
 * @property int $user_id
 * @property int $tag_a_id
 * @property int $tag_b_id
 * @property ConflictScope $scope
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class ConflictRule extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ConflictScope::class,
            'tag_a_id' => 'integer',
            'tag_b_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IngredientTag, $this>
     */
    public function tagA(): BelongsTo
    {
        return $this->belongsTo(IngredientTag::class, 'tag_a_id');
    }

    /**
     * @return BelongsTo<IngredientTag, $this>
     */
    public function tagB(): BelongsTo
    {
        return $this->belongsTo(IngredientTag::class, 'tag_b_id');
    }
}
