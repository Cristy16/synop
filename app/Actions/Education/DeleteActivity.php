<?php

namespace App\Actions\Education;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteActivity
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Delete an activity and its task (the only way that task goes, D-012).
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Activity $activity): void
    {
        Gate::forUser($user)->authorize('delete', $activity);

        DB::transaction(fn () => $this->books->deleteActivity($activity));
    }
}
