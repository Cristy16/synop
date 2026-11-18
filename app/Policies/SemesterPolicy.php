<?php

namespace App\Policies;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (one active semester, the linked task…) live in
 * the Education Actions, which also check the input (D-048).
 */
class SemesterPolicy
{
    public function view(User $user, Semester $semester): Response
    {
        return $this->owns($user, $semester);
    }

    public function update(User $user, Semester $semester): Response
    {
        return $this->owns($user, $semester);
    }

    public function delete(User $user, Semester $semester): Response
    {
        return $this->owns($user, $semester);
    }

    public function restore(User $user, Semester $semester): Response
    {
        return $this->owns($user, $semester);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Semester $semester): Response
    {
        return $user->id === $semester->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
