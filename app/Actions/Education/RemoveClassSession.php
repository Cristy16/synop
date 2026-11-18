<?php

namespace App\Actions\Education;

use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveClassSession
{
    public function __construct(private SchoolBookkeeping $books) {}

    /**
     * Remove a class time and its schedule. Classes keep no attendance in the MVP
     * (DATABASE §4), so there's no history to keep.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, ClassSession $session): void
    {
        Gate::forUser($user)->authorize('delete', $session);

        DB::transaction(fn () => $this->books->deleteSession($session));
    }
}
