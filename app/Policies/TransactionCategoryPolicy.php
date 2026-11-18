<?php

namespace App\Policies;

use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TransactionCategoryPolicy
{
    public function view(User $user, TransactionCategory $category): Response
    {
        return $this->owns($user, $category);
    }

    public function update(User $user, TransactionCategory $category): Response
    {
        return $this->owns($user, $category);
    }

    public function delete(User $user, TransactionCategory $category): Response
    {
        return $this->owns($user, $category);
    }

    /**
     * Another user's category answers 404 (CLAUDE.md rule 11).
     */
    private function owns(User $user, TransactionCategory $category): Response
    {
        return $user->id === $category->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
