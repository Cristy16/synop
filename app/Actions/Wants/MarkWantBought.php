<?php

namespace App\Actions\Wants;

use App\Enums\CategoryKind;
use App\Enums\WantStatus;
use App\Models\User;
use App\Models\Want;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarkWantBought
{
    public function __construct(private WantBookkeeping $books) {}

    /**
     * Mark a want bought, and optionally record its price as an expense from an
     * account (source = want). Finance then shows it "From want" (D-049).
     *
     * @param  array<string, mixed>  $input  bought_on (Y-m-d), record_expense (bool), account_id, category_id
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Want $want, array $input): Want
    {
        Gate::forUser($user)->authorize('update', $want);

        $expense = filter_var($input['record_expense'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $validated = Validator::make($input, [
            'bought_on' => ['required', 'date_format:Y-m-d'],
            // The user's own active account and expense category (rule 10).
            'account_id' => $expense
                ? ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')]
                : ['nullable'],
            'category_id' => $expense
                ? ['required', 'integer', Rule::exists('transaction_categories', 'id')->where('user_id', $user->id)->where('kind', CategoryKind::Expense->value)]
                : ['nullable'],
        ])->validate();

        if ($want->status === WantStatus::Bought) {
            throw ValidationException::withMessages(['bought_on' => __('It\'s already marked bought.')]);
        }

        if ($expense && ($want->price_cents === null || $want->price_cents->isZero())) {
            throw ValidationException::withMessages(['record_expense' => __('Add a price first to record it as an expense.')]);
        }

        return DB::transaction(function () use ($want, $validated, $expense) {
            $want->status = WantStatus::Bought;
            $want->setAttribute('bought_on', $validated['bought_on']);
            $want->save();

            if ($expense) {
                $this->books->recordExpense($want, (int) $validated['account_id'], (int) $validated['category_id']);
            }

            return $want;
        });
    }
}
