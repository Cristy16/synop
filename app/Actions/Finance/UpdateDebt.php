<?php

namespace App\Actions\Finance;

use App\Enums\DebtDirection;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateDebt
{
    use ParsesMoney;

    public function __construct(private DebtBookkeeping $books, private DebtPlanInput $planInput) {}

    /**
     * Edit a debt. Its principal transaction, status and task follow.
     * Once there are payments, the direction can't change and the principal
     * can't go below what has been paid (D-040).
     *
     * @param  array<string, mixed>  $input  same fields as CreateDebt
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Debt $debt, array $input): Debt
    {
        Gate::forUser($user)->authorize('update', $debt);

        $validated = Validator::make($input, DebtInput::rules($user))->validate();
        $principal = $this->parseMoney($validated['principal'], 'principal', 'positive');
        $direction = DebtDirection::from($validated['direction']);
        $plan = $this->planInput->validate($input, $principal, $validated['started_on']);

        return DB::transaction(function () use ($user, $debt, $validated, $principal, $direction, $plan) {
            // Lock the row so a payment can't land between the check and the save.
            $debt = Debt::forUser($user)->lockForUpdate()->findOrFail($debt->id);
            $paid = $debt->paid();

            if (! $paid->isZero() && $direction !== $debt->direction) {
                throw ValidationException::withMessages([
                    'direction' => __('This debt already has payments, so its direction can\'t change.'),
                ]);
            }

            if ($principal->cents() < $paid->cents()) {
                throw ValidationException::withMessages([
                    'principal' => __(':paid has already been paid, so the amount can\'t be less than that.', [
                        'paid' => $paid->format($user->currency),
                    ]),
                ]);
            }

            $debt->contact_id = (int) $validated['contact_id'];
            $debt->direction = $direction;
            $debt->principal_cents = $principal;
            $debt->description = $validated['description'] ?? null;
            $debt->account_id = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
            $debt->setAttribute('started_on', $validated['started_on']);
            $debt->setAttribute('due_on', $plan === null ? ($validated['due_on'] ?? null) : null);
            $debt->installment_cents = $plan['installment'] ?? null;
            $debt->save();

            $this->books->syncPrincipal($debt);
            $this->books->syncPlan($debt, $plan);
            $this->books->refresh($debt); // also syncs the task

            return $debt;
        });
    }
}
