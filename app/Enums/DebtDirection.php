<?php

namespace App\Enums;

/**
 * Who owes whom (debts.direction).
 */
enum DebtDirection: string
{
    /** A contact borrowed from me. */
    case OwedToMe = 'owed_to_me';

    /** I borrowed from a contact. */
    case IOwe = 'i_owe';

    /**
     * The transaction for the principal: lending takes money out, borrowing brings it in (D-007).
     */
    public function principalType(): TransactionType
    {
        return $this === self::OwedToMe ? TransactionType::DebtOut : TransactionType::DebtIn;
    }

    /**
     * The transaction for a payment: being repaid brings money in, repaying takes it out.
     */
    public function paymentType(): TransactionType
    {
        return $this === self::OwedToMe ? TransactionType::DebtIn : TransactionType::DebtOut;
    }

    public function label(): string
    {
        return match ($this) {
            self::OwedToMe => 'Owed to me',
            self::IOwe => 'I owe',
        };
    }
}
