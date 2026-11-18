<?php

namespace App\Actions\Wants;

use App\Enums\WantStatus;
use App\Models\User;
use App\Models\Want;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeWantStatus
{
    public function __construct(private WantBookkeeping $books) {}

    /**
     * Drop a want, or put it back on the list ("wanted"). Putting a bought want back
     * undoes the purchase: its recorded expense is removed, so the money returns to
     * the account (D-049). Use MarkWantBought to mark it bought.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Want $want, WantStatus $status): Want
    {
        Gate::forUser($user)->authorize('update', $want);

        if ($status === WantStatus::Bought) {
            throw ValidationException::withMessages(['status' => __('Use "Mark bought" for that.')]);
        }

        if ($status === WantStatus::Dropped && $want->status === WantStatus::Bought) {
            throw ValidationException::withMessages(['status' => __('Move it back to "wanted" first.')]);
        }

        return DB::transaction(function () use ($want, $status) {
            if ($want->status === WantStatus::Bought) {
                $this->books->expenseOf($want)?->delete();
                $want->bought_on = null;
            }

            $want->status = $status;
            $want->save();

            return $want;
        });
    }
}
