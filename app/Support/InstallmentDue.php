<?php

namespace App\Support;

/**
 * The next installment of a debt: when it's due, how much, and which payment it is.
 */
final readonly class InstallmentDue
{
    public function __construct(
        public string $date,
        public Money $amount,
        public int $number,
        public int $count,
    ) {}
}
