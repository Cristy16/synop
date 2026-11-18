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
        // Courses in a semester (DATABASE §3.6).
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // semester_id gets its own index from its foreign key.
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable(); // "CS 101"
            $table->string('title', 150);
            $table->string('instructor', 100)->nullable();
            $table->string('room', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
