<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives users who registered before Phase 3 the starter categories that new
 * users get from CreateDefaultCategories. The list is copied here on purpose:
 * a migration must keep doing the same thing even if the app's list changes.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'expense' => ['Food', 'Transport', 'School', 'Bills', 'Personal Care', 'Health', 'Shopping', 'Entertainment', 'Other'],
        'income' => ['Allowance', 'Salary', 'Gift', 'Other'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->each(function (object $user) use ($now) {
            $rows = [];

            foreach (self::DEFAULTS as $kind => $names) {
                foreach ($names as $name) {
                    $rows[] = [
                        'user_id' => $user->id,
                        'kind' => $kind,
                        'name' => $name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Skips any (user, kind, name) that already exists.
            DB::table('transaction_categories')->insertOrIgnore($rows);
        });
    }

    /**
     * Nothing to undo: dropping the table (previous migration) removes the rows.
     */
    public function down(): void
    {
        //
    }
};
