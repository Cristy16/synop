<?php

namespace App\Enums;

/**
 * debts.status. A debt is settled when nothing remains (principal − payments = 0).
 */
enum DebtStatus: string
{
    case Open = 'open';
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Settled => 'Settled',
        };
    }
}
