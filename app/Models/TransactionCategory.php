<?php

namespace App\Models;

use App\Enums\CategoryKind;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A label for income or spending: "Food", "Allowance"…
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property CategoryKind $kind
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'kind'])]
class TransactionCategory extends Model
{
    /** @use HasFactory<TransactionCategoryFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
        ];
    }
}
