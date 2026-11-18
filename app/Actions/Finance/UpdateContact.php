<?php

namespace App\Actions\Finance;

use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateContact
{
    public function __construct(private DebtBookkeeping $books) {}

    /**
     * Rename a contact. Their open debts' task titles follow the new name.
     *
     * @param  array<string, mixed>  $input  name, note
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Contact $contact, array $input): Contact
    {
        Gate::forUser($user)->authorize('update', $contact);

        $validated = Validator::make($input, CreateContact::rules())->validate();

        DB::transaction(function () use ($user, $contact, $validated) {
            $contact->fill(['name' => trim($validated['name']), 'note' => $validated['note'] ?? null])->save();

            Debt::forUser($user)->where('contact_id', $contact->id)->each(fn (Debt $debt) => $this->books->syncTask($debt));
        });

        return $contact;
    }
}
