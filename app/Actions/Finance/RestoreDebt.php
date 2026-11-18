<?php

namespace App\Actions\Finance;

use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RestoreDebt
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Bring back a hidden debt with what was hidden with it.
     * Find it with Debt::onlyTrashed()->findOrFail($id).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Debt $debt): Debt
    {
        Gate::forUser($user)->authorize('restore', $debt);

        $contact = Contact::forUser($user)->withTrashed()->findOrFail($debt->contact_id);

        if ($contact->trashed()) {
            throw ValidationException::withMessages([
                'debt' => __('Restore :name first.', ['name' => $contact->name]),
            ]);
        }

        DB::transaction(fn () => $this->books->unhide($debt));

        return $debt;
    }
}
