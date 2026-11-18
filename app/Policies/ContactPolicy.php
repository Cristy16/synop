<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (open debts, overpaying…) live in the debt Actions,
 * because they need input the Policy doesn't get (D-040).
 */
class ContactPolicy
{
    public function view(User $user, Contact $contact): Response
    {
        return $this->owns($user, $contact);
    }

    public function update(User $user, Contact $contact): Response
    {
        return $this->owns($user, $contact);
    }

    public function delete(User $user, Contact $contact): Response
    {
        return $this->owns($user, $contact);
    }

    public function restore(User $user, Contact $contact): Response
    {
        return $this->owns($user, $contact);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Contact $contact): Response
    {
        return $user->id === $contact->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
