<?php

namespace App\Actions\Finance;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation and filling shared by RecordTransaction and UpdateTransaction.
 * Covers the hand-entered types only (income, expense, transfer).
 */
class TransactionInput
{
    /**
     * The type rules from DATABASE §3.2, plus "every id must be the user's own" (rule 10).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    public static function rules(User $user, array $input): array
    {
        $type = TransactionType::tryFrom((string) ($input['type'] ?? ''));
        $kind = $type?->categoryKind();

        return [
            'type' => ['required', Rule::in(array_map(fn (TransactionType $t) => $t->value, TransactionType::manual()))],
            'account_id' => ['required', 'integer', self::activeAccountOf($user)],
            'to_account_id' => $type === TransactionType::Transfer
                ? ['required', 'integer', 'different:account_id', self::activeAccountOf($user)]
                : ['prohibited'],
            // Income needs an income category, expense an expense category; transfers have none.
            'category_id' => $kind !== null
                ? ['required', 'integer', Rule::exists('transaction_categories', 'id')->where('user_id', $user->id)->where('kind', $kind->value)]
                : ['prohibited'],
            'amount' => ['required', 'string', 'max:30'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fill(Transaction $transaction, array $validated, Money $amount): void
    {
        $transaction->type = TransactionType::from($validated['type']);
        $transaction->account_id = (int) $validated['account_id'];
        $transaction->to_account_id = isset($validated['to_account_id']) ? (int) $validated['to_account_id'] : null;
        $transaction->category_id = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $transaction->amount_cents = $amount;
        $transaction->setAttribute('occurred_on', $validated['occurred_on']);
        $transaction->note = $validated['note'] ?? null;
    }

    private static function activeAccountOf(User $user): Exists
    {
        return Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at');
    }
}
