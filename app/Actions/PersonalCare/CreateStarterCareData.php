<?php

namespace App\Actions\PersonalCare;

use App\Enums\RoutinePeriod;
use App\Models\IngredientTag;
use App\Models\Routine;
use App\Models\User;

class CreateStarterCareData
{
    /**
     * Editable starter ingredient groups (D-018). Only groups: no conflict rules,
     * which users write themselves; the app gives no skincare advice.
     */
    public const STARTER_TAGS = [
        'AHA' => ['glycolic acid', 'lactic acid', 'mandelic acid', 'malic acid', 'tartaric acid'],
        'BHA' => ['salicylic acid', 'betaine salicylate', 'willow bark'],
        'Retinoid' => ['retinol', 'retinal', 'retinaldehyde', 'retinyl palmitate', 'tretinoin', 'adapalene', 'hydroxypinacolone retinoate'],
        'Vitamin C' => ['ascorbic acid', 'ethyl ascorbic acid', 'sodium ascorbyl phosphate', 'magnesium ascorbyl phosphate', 'ascorbyl glucoside', 'tetrahexyldecyl ascorbate'],
        'Niacinamide' => ['niacinamide'],
        'Benzoyl peroxide' => ['benzoyl peroxide'],
    ];

    /**
     * Give a user their AM and PM routines (D-017) and the starter tags.
     * Safe to run twice: only what's missing is created.
     */
    public function handle(User $user): void
    {
        $periods = Routine::forUser($user)->pluck('period')->map(fn (RoutinePeriod $p) => $p->value)->all();

        foreach (RoutinePeriod::cases() as $period) {
            if (! in_array($period->value, $periods, true)) {
                $routine = new Routine;
                // Set explicitly: during registration nobody is logged in yet.
                $routine->user_id = $user->id;
                $routine->period = $period;
                $routine->name = $period->defaultName();
                $routine->save();
            }
        }

        $names = IngredientTag::forUser($user)->pluck('name')->all();

        foreach (self::STARTER_TAGS as $name => $keywords) {
            if (! in_array($name, $names, true)) {
                $tag = new IngredientTag(['name' => $name, 'keywords' => $keywords]);
                $tag->user_id = $user->id;
                $tag->save();
            }
        }
    }
}
