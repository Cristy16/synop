<?php

namespace App\Enums;

/**
 * What a transaction is (transactions.type). The amount is always positive;
 * the type decides whether it adds to or takes from the account (DATABASE §3.2).
 *
 * debt_in / debt_out are created only by the debt Actions. They change the
 * balance but are left out of income and spending reports (D-007).
 */
enum TransactionType: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case DebtIn = 'debt_in';
    case DebtOut = 'debt_out';

    /**
     * Types the user records by hand (RecordTransaction).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Income, self::Expense, self::Transfer];
    }

    /**
     * The category kind this type needs, or null when it has no category.
     */
    public function categoryKind(): ?CategoryKind
    {
        return match ($this) {
            self::Income => CategoryKind::Income,
            self::Expense => CategoryKind::Expense,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Income',
            self::Expense => 'Expense',
            self::Transfer => 'Transfer',
            self::DebtIn => 'Debt in',
            self::DebtOut => 'Debt out',
        };
    }
}
