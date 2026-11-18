<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Makes a model private to its owner (ARCHITECTURE §4, D-024).
 *
 * - Every query is limited to the logged-in user's rows.
 * - With no one logged in (console, queued jobs) queries return no rows.
 *   Jobs opt out explicitly with `Model::forUser($user)`.
 * - `user_id` is filled from the logged-in user on create. A logged-in user
 *   can't create records for someone else, and `user_id` never changes.
 *   (In tests, create user B's data while acting as B.)
 *
 * Never put `user_id` in a model's `#[Fillable]`.
 *
 * @mixin Model
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            $userId = Auth::id();

            if ($userId === null) {
                // Fail closed: nobody logged in means nobody's data.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('user_id'), $userId);
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') === null) {
                $model->setAttribute('user_id', Auth::id());
            }

            if ($model->getAttribute('user_id') === null) {
                throw new LogicException(
                    'No user is logged in: set user_id explicitly when creating '.$model::class.'.'
                );
            }

            // A logged-in user can only create their own records.
            if (Auth::check() && (int) $model->getAttribute('user_id') !== (int) Auth::id()) {
                throw new LogicException(
                    'Cannot create '.$model::class.' for another user while logged in.'
                );
            }
        });

        // A record never moves to another user.
        static::updating(function (Model $model): void {
            if ($model->isDirty('user_id')) {
                throw new LogicException('The owner (user_id) of '.$model::class.' cannot be changed.');
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one explicit opt-out of the owner scope, for jobs and commands
     * that work on a single user's data: `Task::forUser($user)->get()`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForUser(Builder $query, User|int $user): void
    {
        $query->withoutGlobalScope('user')
            ->where($query->qualifyColumn('user_id'), $user instanceof User ? $user->id : $user);
    }
}
