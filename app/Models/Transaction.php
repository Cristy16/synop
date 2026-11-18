<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One money movement. The amount is always positive; `type` decides its
 * direction (DATABASE §3.2). Rows with a `source` (a debt, a payment…) are
 * created and changed only by that source's Action.
 *
 * Only the Finance Actions set these columns, so none are fillable.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $to_account_id
 * @property TransactionType $type
 * @property Money $amount_cents
 * @property int|null $category_id
 * @property CarbonImmutable $occurred_on
 * @property string|null $note
 * @property string|null $source_type
 * @property int|null $source_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount_cents' => MoneyCast::class,
            'occurred_on' => 'date',
            'source_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        // withTrashed: a transaction still shows its account after the account is deleted.
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class);
    }

    /**
     * The record that created this transaction, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Created by a debt, payment, product or want: change it through that record instead.
     */
    public function hasSource(): bool
    {
        return $this->source_type !== null;
    }
}
