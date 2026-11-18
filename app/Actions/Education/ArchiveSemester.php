<?php

namespace App\Actions\Education;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ArchiveSemester
{
    /**
     * Archive (true) or unarchive (false). Archived semesters' classes are hidden
     * from Today; archiving the active semester also turns "active" off (D-048).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Semester $semester, bool $archived = true): void
    {
        Gate::forUser($user)->authorize('update', $semester);

        if ($archived) {
            $semester->archived_at ??= now();
            $semester->is_active = false;
        } else {
            $semester->archived_at = null;
        }

        $semester->save();
    }
}
