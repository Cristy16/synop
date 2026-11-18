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
        // The user's own rules: "AHA — not with Retinoid in the same routine" (D-018).
        Schema::create('conflict_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Stored with the smaller id first, so A–B and B–A are the same rule.
            $table->foreignId('tag_a_id')->constrained('ingredient_tags')->cascadeOnDelete();
            $table->foreignId('tag_b_id')->constrained('ingredient_tags')->cascadeOnDelete();
            $table->string('scope', 20); // same_routine · same_day
            $table->string('note')->nullable(); // shown in the warning
            $table->timestamps();

            $table->unique(['user_id', 'tag_a_id', 'tag_b_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conflict_rules');
    }
};
