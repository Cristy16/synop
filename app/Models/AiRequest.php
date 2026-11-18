<?php

namespace App\Models;

use App\Enums\AiRequestKind;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One real AI call (no content stored). Counts toward the 10-a-day limit.
 *
 * @property int $id
 * @property int $user_id
 * @property AiRequestKind $kind
 * @property CarbonImmutable $requested_on
 * @property bool $succeeded
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([])]
class AiRequest extends Model
{
    use BelongsToUser;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AiRequestKind::class,
            'requested_on' => 'date',
            'succeeded' => 'boolean',
        ];
    }
}
