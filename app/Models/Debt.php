<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money lent to or borrowed from a contact. "Remaining" is computed from the
 * payments, never stored (D-011). Changed only by the Finance debt Actions,
 * so nothing is fillable.
 *
 * @property int $id
 * @property int $user_id
 * @property int $contact_id
 * @property DebtDirection $direction
 * @property Money $principal_cents
 * @property string|null $description
 * @property int|null $account_id
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $due_on
 * @property Money|null $installment_cents
 * @property DebtStatus $status
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([])]
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => DebtDirection::class,
            'status' => DebtStatus::class,
            'principal_cents' => MoneyCast::class,
            'installment_cents' => MoneyCast::class,
            'started_on' => 'date',
            'due_on' => 'date',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /**
     * @return HasMany<DebtPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    /**
     * Sum of the payments that aren't deleted.
     */
    public function paid(): Money
    {
        // forUser instead of payments(): works the same whoever is logged in (or nobody).
        return Money::fromCents((int) DebtPayment::forUser($this->user_id)
            ->where('debt_id', $this->id)
            ->sum('amount_cents'));
    }

    public function remaining(): Money
    {
        return $this->principal_cents->subtract($this->paid());
    }

    public function isSettled(): bool
    {
        return $this->status === DebtStatus::Settled;
    }
}
