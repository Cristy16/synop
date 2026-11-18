<?php

namespace App\Actions\Finance;

use App\Enums\DebtDirection;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Validation shared by CreateDebt and UpdateDebt. One-off debts only for now;
 * installments arrive with the Schedule Engine (Phase 4).
 */
class DebtInput
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(User $user): array
    {
        return [
            // Every id must be the user's own and not deleted (rule 10).
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'direction' => ['required', Rule::enum(DebtDirection::class)],
            'principal' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')],
            'started_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
        ];
    }
}
