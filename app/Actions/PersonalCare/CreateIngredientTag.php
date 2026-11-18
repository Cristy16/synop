<?php

namespace App\Actions\PersonalCare;

use App\Models\IngredientTag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateIngredientTag
{
    public function __construct(private SyncIngredientTags $sync) {}

    /**
     * Add an ingredient group and link the products whose ingredients match it.
     *
     * @param  array<string, mixed>  $input  name, keywords (text)
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): IngredientTag
    {
        $values = IngredientTagInput::validate($user, $input);

        return DB::transaction(function () use ($user, $values) {
            $tag = new IngredientTag($values);
            $tag->user_id = $user->id;
            $tag->save();

            $this->sync->forTag($tag);

            return $tag;
        });
    }
}
