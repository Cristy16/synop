<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Ownership only. Business rules (one active semester, the linked task…) live in
 * the Education Actions, which also check the input (D-048).
 */
class CoursePolicy
{
    public function view(User $user, Course $course): Response
    {
        return $this->owns($user, $course);
    }

    public function update(User $user, Course $course): Response
    {
        return $this->owns($user, $course);
    }

    public function delete(User $user, Course $course): Response
    {
        return $this->owns($user, $course);
    }

    public function restore(User $user, Course $course): Response
    {
        return $this->owns($user, $course);
    }

    /**
     * Another user's record answers 404, as if it didn't exist (CLAUDE.md rule 11).
     */
    private function owns(User $user, Course $course): Response
    {
        return $user->id === $course->user_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
