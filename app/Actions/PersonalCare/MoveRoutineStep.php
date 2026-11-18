<?php

namespace App\Actions\PersonalCare;

use App\Models\RoutineStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MoveRoutineStep
{
    /**
     * Move a step one place up (-1) or down (+1) in its routine. The routine's
     * steps are renumbered 1, 2, 3… in the new order.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, RoutineStep $step, int $direction): void
    {
        Gate::forUser($user)->authorize('update', $step);

        DB::transaction(function () use ($user, $step, $direction) {
            $ids = RoutineStep::forUser($user)
                ->where('routine_id', $step->routine_id)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $from = null;
            foreach ($ids as $index => $id) {
                if ($id === $step->id) {
                    $from = $index;
                }
            }

            $to = $from === null ? null : $from + ($direction < 0 ? -1 : 1);

            if ($from === null || $to === null || $to < 0 || $to >= count($ids)) {
                return;
            }

            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $index => $id) {
                RoutineStep::forUser($user)->whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
