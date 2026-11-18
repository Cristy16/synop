<?php

namespace App\Actions\Wants;

use App\Models\User;
use App\Models\Want;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateWant
{
    public function __construct(private WantInput $input, private WantBookkeeping $books) {}

    /**
     * Edit a want. If it was bought with an expense, the expense follows.
     *
     * @param  array<string, mixed>  $input  see WantInput
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Want $want, array $input): Want
    {
        Gate::forUser($user)->authorize('update', $want);

        $values = $this->input->validate($user, $input);

        if ($this->books->expenseOf($want) !== null && ($values['price_cents'] === null || $values['price_cents']->isZero())) {
            throw ValidationException::withMessages(['price' => __('It was recorded as an expense, so it needs a price above zero.')]);
        }

        return DB::transaction(function () use ($want, $values) {
            WantFields::fill($want, $values);
            $want->save();

            $this->books->keepInStep($want);

            return $want;
        });
    }
}
