<?php

namespace App\Actions\Finance;

use App\Enums\CategoryKind;
use App\Models\TransactionCategory;
use App\Models\User;

class CreateDefaultCategories
{
    /**
     * The starter categories every new user gets (D-039). Users can rename or add their own later.
     */
    public const DEFAULTS = [
        'expense' => ['Food', 'Transport', 'School', 'Bills', 'Personal Care', 'Health', 'Shopping', 'Entertainment', 'Other'],
        'income' => ['Allowance', 'Salary', 'Gift', 'Other'],
    ];

    /**
     * Create the starter categories the user doesn't have yet. Safe to run twice.
     */
    public function handle(User $user): void
    {
        $existing = TransactionCategory::forUser($user)
            ->get(['kind', 'name'])
            ->map(fn (TransactionCategory $c) => $c->kind->value.'|'.$c->name)
            ->all();

        foreach (self::DEFAULTS as $kind => $names) {
            foreach ($names as $name) {
                if (in_array($kind.'|'.$name, $existing, true)) {
                    continue;
                }

                $category = new TransactionCategory(['name' => $name, 'kind' => CategoryKind::from($kind)]);
                // Set explicitly: during registration nobody is logged in yet.
                $category->user_id = $user->id;
                $category->save();
            }
        }
    }
}
