<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\FetchStatus;
use App\Enums\TaskPriority;
use App\Enums\WantProductType;
use App\Enums\WantStatus;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the user wants to buy. Saved only by the Wants Actions, which also
 * keep the optional expense (source = want) in step (D-049).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $url
 * @property string|null $shop
 * @property string $title
 * @property string|null $description
 * @property Money|null $price_cents
 * @property string|null $image_url
 * @property WantProductType|null $product_type
 * @property int|null $expense_category_id
 * @property string|null $ingredients_text
 * @property TaskPriority $priority
 * @property WantStatus $status
 * @property FetchStatus $fetch_status
 * @property CarbonImmutable|null $bought_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([])]
class Want extends Model
{
    use BelongsToUser;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'normal',
        'status' => 'wanted',
        'fetch_status' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => MoneyCast::class,
            'product_type' => WantProductType::class,
            'priority' => TaskPriority::class,
            'status' => WantStatus::class,
            'fetch_status' => FetchStatus::class,
            'bought_on' => 'date',
            'expense_category_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'expense_category_id');
    }
}
