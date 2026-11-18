<?php

namespace App\Actions\Finance;

use App\Enums\DebtDirection;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateDebt
{
    use ParsesMoney;

    public function __construct(private DebtBookkeeping $books, private DebtPlanInput $planInput) {}

    /**
     * Record money lent to (owed_to_me) or borrowed from (i_owe) a contact.
     * With an account, the principal also moves money (debt_out / debt_in).
     * With a due date (or installments), a linked task is created.
     *
     * @param  array<string, mixed>  $input  contact_id, direction, principal (text), description,
     *                                       account_id (optional), started_on, due_on (optional), dates as Y-m-d;
     *                                       for installments also plan = 'installments', installment (text),
     *                                       frequency, interval | weekdays | day_of_month, first_due_on (D-044)
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Debt
    {
        $validated = Validator::make($input, DebtInput::rules($user))->validate();
        $principal = $this->parseMoney($validated['principal'], 'principal', 'positive');
        $plan = $this->planInput->validate($input, $principal, $validated['started_on']);

        return DB::transaction(function () use ($user, $validated, $principal, $plan) {
            $debt = new Debt;
            $debt->user_id = $user->id;
            $debt->contact_id = (int) $validated['contact_id'];
            $debt->direction = DebtDirection::from($validated['direction']);
            $debt->principal_cents = $principal;
            $debt->description = $validated['description'] ?? null;
            $debt->account_id = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
            $debt->setAttribute('started_on', $validated['started_on']);
            $debt->setAttribute('due_on', $plan === null ? ($validated['due_on'] ?? null) : null);
            $debt->installment_cents = $plan['installment'] ?? null;
            $debt->save();

            $this->books->syncPrincipal($debt);
            $this->books->syncPlan($debt, $plan);
            $this->books->syncTask($debt);

            return $debt;
        });
    }
}
