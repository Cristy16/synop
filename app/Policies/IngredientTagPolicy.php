<?php

namespace App\Policies;

use App\Models\IngredientTag;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (no duplicates, own products only…) live in the
 * Personal Care Actions, which also check the input (D-046).
 */
class IngredientTagPolicy
{
    public function view(User $user, IngredientTag $tag): Response
    {
        return $this->owns($user, $tag);
    }

    public function update(User $user, IngredientTag $tag): Response
    {
        return $this->owns($user, $tag);
    }

    public function delete(User $user, IngredientTag $tag): Response
    {
        return $this->owns($user, $tag);
    }

    public function restore(User $user, IngredientTag $tag): Response
    {
        return $this->owns($user, $tag);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, IngredientTag $tag): Response
    {
        return $user->id === $tag->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
