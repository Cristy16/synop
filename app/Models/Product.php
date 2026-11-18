<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A skincare, makeup or vitamin product. Saved only by the Personal Care
 * Actions, which also keep `expires_on` and the optional expense in step.
 *
 * @property int $id
 * @property int $user_id
 * @property ProductType $type
 * @property string|null $brand
 * @property string $name
 * @property string|null $category
 * @property Money|null $price_cents
 * @property CarbonImmutable|null $purchased_on
 * @property CarbonImmutable|null $opened_on
 * @property int|null $pao_months
 * @property CarbonImmutable|null $printed_expiry_on
 * @property CarbonImmutable|null $expires_on
 * @property int $quantity
 * @property ProductStatus $status
 * @property string|null $ingredients_text
 * @property int|null $units_left
 * @property int|null $low_stock_threshold
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'status' => 'unopened',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'status' => ProductStatus::class,
            'price_cents' => MoneyCast::class,
            'purchased_on' => 'date',
            'opened_on' => 'date',
            'printed_expiry_on' => 'date',
            'expires_on' => 'date',
            'pao_months' => 'integer',
            'quantity' => 'integer',
            'units_left' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * Ingredient groups its ingredients match (rebuilt by the Personal Care Actions).
     *
     * @return BelongsToMany<IngredientTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(IngredientTag::class);
    }

    /**
     * Vitamins: the dose times, earliest first.
     *
     * @return HasMany<VitaminDose, $this>
     */
    public function doses(): HasMany
    {
        return $this->hasMany(VitaminDose::class)->orderBy('dose_time')->orderBy('id');
    }

    /**
     * "Brand Name", or just the name.
     */
    public function displayName(): string
    {
        return trim(($this->brand ?? '').' '.$this->name);
    }
}
