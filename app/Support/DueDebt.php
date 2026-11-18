<?php

namespace App\Support;

use App\Models\Debt;

/**
 * A debt in "Payments Due": its date (the due date, or an installment debt's next
 * due date) and the amount due on it.
 */
final readonly class DueDebt
{
    public function __construct(
        public Debt $debt,
        public string $date,
        public Money $amount,
        public ?InstallmentDue $installment = null,
    ) {}
}
