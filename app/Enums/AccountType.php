<?php

namespace App\Enums;

/**
 * Where an account's money is kept (accounts.type).
 */
enum AccountType: string
{
    case Cash = 'cash';
    case Ewallet = 'ewallet';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Ewallet => 'E-wallet',
            self::Bank => 'Bank',
        };
    }
}
