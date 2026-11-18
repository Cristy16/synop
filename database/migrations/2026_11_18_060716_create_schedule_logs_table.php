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
        // Done / skipped, one row per occurrence. Un-ticking deletes the row.
        Schema::create('schedule_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_rule_id')->constrained()->cascadeOnDelete();
            $table->date('occurs_on'); // the user's local date of the occurrence
            $table->string('status', 20); // done · skipped
            $table->timestamp('logged_at'); // UTC instant the user tapped it
            $table->timestamps();

            $table->unique(['schedule_rule_id', 'occurs_on']);
            $table->index(['user_id', 'occurs_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_logs');
    }
};
