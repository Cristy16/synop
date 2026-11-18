<?php

namespace App\Services;

use App\Actions\PersonalCare\SyncIngredientTags;
use App\Enums\ConflictScope;
use App\Enums\ProductStatus;
use App\Models\ConflictRule;
use App\Models\IngredientTag;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * For a skincare or makeup want: which of the user's ingredient groups its
 * ingredients match, which of the user's own conflict rules it would meet against
 * products they already have, and which groups they already own (PRD §2.5).
 * Read-only. It reports the user's rules, never advice (D-018).
 */
class IngredientCheck
{
    /**
     * @return array{groups: list<string>, conflicts: list<array{rule: string, scope: string, note: string|null, owned: list<string>}>, duplicates: list<array{group: string, owned: list<string>}>}
     */
    public function for(User $user, ?string $ingredients): array
    {
        $result = ['groups' => [], 'conflicts' => [], 'duplicates' => []];

        if ($ingredients === null || trim($ingredients) === '') {
            return $result;
        }

        $tags = IngredientTag::forUser($user)->orderBy('name')->get();
        $matched = $tags->filter(fn (IngredientTag $t) => SyncIngredientTags::matches($ingredients, $t->keywords));
        $result['groups'] = array_values($matched->pluck('name')->all());

        if ($matched->isEmpty()) {
            return $result;
        }

        // Products still on the shelf, with their groups.
        $owned = Product::forUser($user)
            ->whereIn('status', [ProductStatus::Unopened->value, ProductStatus::InUse->value])
            ->with(['tags' => fn ($q) => $q->withoutGlobalScope('user')])
            ->get();
        $ownedWith = fn (int $tagId): Collection => $owned->filter(fn (Product $p) => $p->tags->contains('id', $tagId))->values();

        foreach ($matched as $tag) {
            $same = $ownedWith($tag->id);

            if ($same->isNotEmpty()) {
                $result['duplicates'][] = ['group' => $tag->name, 'owned' => array_values($same->map(fn (Product $p) => $p->displayName())->all())];
            }
        }

        $rules = ConflictRule::forUser($user)->with(['tagA' => fn ($q) => $q->withoutGlobalScope('user'), 'tagB' => fn ($q) => $q->withoutGlobalScope('user')])->get();

        foreach ($rules as $rule) {
            foreach ([[$rule->tag_a_id, $rule->tag_b_id], [$rule->tag_b_id, $rule->tag_a_id]] as [$mine, $other]) {
                if (! $matched->contains('id', $mine)) {
                    continue;
                }

                $clashing = $ownedWith($other);

                if ($clashing->isNotEmpty()) {
                    $result['conflicts'][] = [
                        'rule' => $rule->tagA->name.' ✕ '.$rule->tagB->name,
                        'scope' => $rule->scope === ConflictScope::SameRoutine ? 'same routine' : 'same day',
                        'note' => $rule->note,
                        'owned' => array_values($clashing->map(fn (Product $p) => $p->displayName())->all()),
                    ];
                    break;
                }
            }
        }

        return $result;
    }
}
