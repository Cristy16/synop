<?php

namespace App\Actions\PersonalCare;

use App\Enums\ConflictScope;
use App\Models\ConflictRule;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreateConflictRule
{
    /**
     * "AHA — not with Retinoid in the same routine." The pair is stored with the
     * smaller id first, so A–B and B–A are the same rule (DATABASE §3.5).
     *
     * @param  array<string, mixed>  $input  tag_a_id, tag_b_id, scope, note
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $input): ConflictRule
    {
        $ownTag = fn () => Rule::exists('ingredient_tags', 'id')->where('user_id', $user->id);

        $validated = Validator::make($input, [
            'tag_a_id' => ['required', 'integer', $ownTag()],
            'tag_b_id' => ['required', 'integer', 'different:tag_a_id', $ownTag()],
            'scope' => ['required', Rule::enum(ConflictScope::class)],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'tag_b_id.different' => __('Choose two different groups.'),
        ])->validate();

        [$a, $b] = [min((int) $validated['tag_a_id'], (int) $validated['tag_b_id']), max((int) $validated['tag_a_id'], (int) $validated['tag_b_id'])];
        $scope = ConflictScope::from($validated['scope']);

        $exists = ConflictRule::forUser($user)->where('tag_a_id', $a)->where('tag_b_id', $b)->where('scope', $scope->value)->exists();

        if ($exists) {
            throw ValidationException::withMessages(['tag_b_id' => __('You already have this rule.')]);
        }

        $rule = new ConflictRule;
        $rule->user_id = $user->id;
        $rule->tag_a_id = $a;
        $rule->tag_b_id = $b;
        $rule->scope = $scope;
        $rule->note = isset($validated['note']) && trim($validated['note']) !== '' ? trim($validated['note']) : null;
        $rule->save();

        return $rule;
    }
}
