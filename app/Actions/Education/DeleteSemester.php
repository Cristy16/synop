<?php

namespace App\Actions\Education;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteSemester
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Delete a semester with its courses, class times (and their schedules) and
     * activities (and their tasks). For keeping history, archive instead.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Semester $semester): void
    {
        Gate::forUser($user)->authorize('delete', $semester);

        DB::transaction(fn () => $this->books->deleteSemester($semester));
    }
}
