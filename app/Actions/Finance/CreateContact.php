<?php

namespace App\Actions\Finance;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateContact
{
    /**
     * @param  array<string, mixed>  $input  name, note
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Contact
    {
        $validated = Validator::make($input, self::rules())->validate();

        $contact = new Contact(['name' => trim($validated['name']), 'note' => $validated['note'] ?? null]);
        $contact->user_id = $user->id;
        $contact->save();

        return $contact;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
