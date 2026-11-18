<?php

namespace App\Actions\Education;

use App\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteCourse
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Delete a course with its class times (and schedules) and activities (and tasks).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Course $course): void
    {
        Gate::forUser($user)->authorize('delete', $course);

        DB::transaction(fn () => $this->books->deleteCourse($course));
    }
}
