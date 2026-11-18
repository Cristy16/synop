<?php

namespace App\Enums;

/**
 * Whether a category is for money coming in or going out (transaction_categories.kind).
 */
enum CategoryKind: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }
}
