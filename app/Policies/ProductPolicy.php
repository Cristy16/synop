<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (expiry, the expense, the delete question) live in the Personal Care Actions,
 * which also need input the Policy doesn't get (D-045).
 */
class ProductPolicy
{
    public function view(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    public function update(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    public function delete(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    public function restore(User $user, Product $product): Response
    {
        return $this->owns($user, $product);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Product $product): Response
    {
        return $user->id === $product->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
