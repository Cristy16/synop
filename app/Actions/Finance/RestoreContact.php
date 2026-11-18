<?php

namespace App\Actions\Finance;

use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RestoreContact
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Bring back a hidden contact and exactly the debts hidden with them.
     * Find it with Contact::onlyTrashed()->findOrFail($id).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Contact $contact): Contact
    {
        Gate::forUser($user)->authorize('restore', $contact);

        DB::transaction(function () use ($user, $contact) {
            $at = $contact->getRawOriginal('deleted_at');

            Debt::forUser($user)->onlyTrashed()
                ->where('contact_id', $contact->id)
                ->where('deleted_at', $at)
                ->each(fn (Debt $debt) => $this->books->unhide($debt));

            $contact->restore();
        });

        return $contact;
    }
}
