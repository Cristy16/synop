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
        // Quizzes, exams, projects, assignments. Each has a linked task (source = activity).
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // quiz · exam · project · assignment
            $table->string('title');
            $table->date('due_on'); // a calendar date as the user means it
            $table->time('due_time')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
