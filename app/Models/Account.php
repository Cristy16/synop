<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountType;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Where money is kept: a wallet, an e-wallet like GCash, a bank account.
 * Its balance is computed by AccountBalances, never stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property Money $opening_balance_cents
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['name', 'type', 'opening_balance_cents'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'opening_balance_cents' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'opening_balance_cents' => MoneyCast::class,
        ];
    }

    /**
     * Transactions where money leaves or enters this account (not transfers in).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Transfers into this account.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transfersIn(): HasMany
    {
        return $this->hasMany(Transaction::class, 'to_account_id');
    }
}
