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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            // A calendar date and local wall-clock time, as the user means them (never converted).
            $table->date('due_on')->nullable();
            $table->time('due_time')->nullable();
            $table->string('priority', 20)->default('normal');
            // A UTC instant.
            $table->timestamp('completed_at')->nullable();
            // Optional link to the record that created this task (morph alias: activity, debt, product).
            // Written by hand instead of nullableMorphs() so the index can be unique: one task per source.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'completed_at', 'due_on']);
            $table->unique(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
