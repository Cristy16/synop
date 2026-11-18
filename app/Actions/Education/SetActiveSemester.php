<?php

namespace App\Actions\Education;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SetActiveSemester
{
    /**
     * Make this the active semester (the one School opens on); the others are
     * turned off in the same transaction (DATABASE §3.6). Archived ones can't be active.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Semester $semester): void
    {
        Gate::forUser($user)->authorize('update', $semester);

        if ($semester->isArchived()) {
            throw ValidationException::withMessages(['semester' => __('Unarchive this semester first.')]);
        }

        DB::transaction(function () use ($user, $semester) {
            Semester::forUser($user)->whereKeyNot($semester->id)->where('is_active', true)->update(['is_active' => false]);

            $semester->is_active = true;
            $semester->save();
        });
    }
}
