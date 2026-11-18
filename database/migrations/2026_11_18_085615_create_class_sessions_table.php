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
        // One row per time slot: "MWF 8:00–10:00" is one session; each has a schedule rule.
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // course_id gets its own index from its foreign key.
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // Local wall-clock times (rule 9); end after start.
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room', 50)->nullable(); // overrides courses.room
            $table->string('label', 50)->nullable(); // "Lecture", "Lab"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
