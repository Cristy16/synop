<?php

namespace App\Actions\PersonalCare;

use App\Models\IngredientTag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateIngredientTag
{
    public function __construct(private SyncIngredientTags $sync) {}

    /**
     * Rename a group or change its ingredient names; its products are matched again.
     *
     * @param  array<string, mixed>  $input  name, keywords (text)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, IngredientTag $tag, array $input): IngredientTag
    {
        Gate::forUser($user)->authorize('update', $tag);

        $values = IngredientTagInput::validate($user, $input, $tag->id);

        return DB::transaction(function () use ($tag, $values) {
            $tag->fill($values)->save();
            $this->sync->forTag($tag);

            return $tag;
        });
    }
}
