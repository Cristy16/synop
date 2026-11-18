<?php

namespace App\Support;

use App\Models\Debt;

/**
 * How safe-to-spend was worked out, line by line (D-008, D-050).
 * `safe` can be negative: you'd be short by that much.
 */
final readonly class SafeToSpendBreakdown
{
    /**
     * @param  list<array{debt: Debt, amount: Money, due_on: string}>  $owed  what you owe that falls due before `until`
     */
    public function __construct(
        public string $until,
        public Money $balance,
        public array $owed,
        public Money $owedTotal,
        public Money $buffer,
        public Money $safe,
    ) {}
}
