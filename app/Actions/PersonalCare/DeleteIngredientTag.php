<?php

namespace App\Actions\PersonalCare;

use App\Models\IngredientTag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteIngredientTag
{
    /**
     * Delete an ingredient group. Its product links and the conflict rules that
     * use it go with it (cascading foreign keys).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, IngredientTag $tag): void
    {
        Gate::forUser($user)->authorize('delete', $tag);

        $tag->delete();
    }
}
