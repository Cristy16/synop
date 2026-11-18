<?php

namespace App\Actions\Wants;

use App\Models\User;
use App\Models\Want;
use Illuminate\Validation\ValidationException;

class CreateWant
{
    public function __construct(private WantInput $input) {}

    /**
     * Add something to the wants list. The page may have filled the details from
     * the link or the pasted description; the user can always type them (D-049).
     *
     * @param  array<string, mixed>  $input  see WantInput
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): Want
    {
        $want = new Want;
        $want->user_id = $user->id;
        WantFields::fill($want, $this->input->validate($user, $input));
        $want->save();

        return $want;
    }
}
