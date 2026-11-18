<?php

namespace App\Actions\Finance;

use App\Enums\DebtStatus;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteContact
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Hide a contact (soft delete, D-040).
     *
     * - No open debts: only the contact is hidden; settled debts stay as history.
     * - Open debts: refused, unless $confirmName is the contact's exact name. Then the
     *   contact and ALL their debts, payments, transactions and tasks are hidden, as if
     *   they never existed. RestoreContact brings everything back.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Contact $contact, ?string $confirmName = null): void
    {
        Gate::forUser($user)->authorize('delete', $contact);

        $debts = Debt::forUser($user)->where('contact_id', $contact->id)->get();
        $hasOpenDebts = $debts->contains(fn (Debt $debt) => $debt->status === DebtStatus::Open);

        if ($hasOpenDebts && trim((string) $confirmName) !== $contact->name) {
            throw ValidationException::withMessages([
                'confirm_name' => __('Settle or delete :name\'s open debts first, or type ":name" to delete :name and all their debts.', [
                    'name' => $contact->name,
                ]),
            ]);
        }

        DB::transaction(function () use ($user, $contact, $debts, $hasOpenDebts) {
            // One moment for everything, so RestoreContact brings back exactly this set.
            $at = now()->startOfSecond();

            if ($hasOpenDebts) {
                $debts->each(fn (Debt $debt) => $this->books->hide($debt, $at));
            }

            Contact::forUser($user)->whereKey($contact->id)->update(['deleted_at' => $at]);
            $contact->deleted_at = $at;
        });
    }
}
