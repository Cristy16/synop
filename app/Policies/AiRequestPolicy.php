<?php

namespace App\Policies;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (the expense, the delete question) live in the AiRequests Actions,
 * which also need input the Policy doesn't get (D-051).
 */
class AiRequestPolicy
{
    public function view(User $user, AiRequest $request): Response
    {
        return $this->owns($user, $request);
    }

    public function update(User $user, AiRequest $request): Response
    {
        return $this->owns($user, $request);
    }

    public function delete(User $user, AiRequest $request): Response
    {
        return $this->owns($user, $request);
    }

    public function restore(User $user, AiRequest $request): Response
    {
        return $this->owns($user, $request);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, AiRequest $request): Response
    {
        return $user->id === $request->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
