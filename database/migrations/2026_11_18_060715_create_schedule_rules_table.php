<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Which dates something repeats on (DATABASE §3.2, D-042). Times of day live on the item.
        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Morph alias of the repeating item: task, debt, routine_step, vitamin_dose, class_session.
            $table->string('schedulable_type', 40);
            $table->unsignedBigInteger('schedulable_id');
            $table->string('frequency', 20); // daily · every_n_days · weekly · monthly
            $table->unsignedSmallInteger('interval')->nullable(); // every_n_days: 2–365, counted from starts_on
            $table->json('weekdays')->nullable(); // weekly: ISO weekdays, Mon = 1 … Sun = 7
            $table->unsignedTinyInteger('day_of_month')->nullable(); // monthly: 1–31 (31 → last day)
            // Local calendar dates (rule 9). ends_on is inclusive; null = no end.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index(['schedulable_type', 'schedulable_id', 'starts_on']);
            $table->index(['user_id', 'paused_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_rules');
    }
};
