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
        // Ordered steps; each has its own schedule rule (default: daily from the day it was added).
        Schema::create('routine_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('note')->nullable(); // "Wait 10 minutes"
            $table->timestamps();
            // Soft delete: removed steps keep their schedule and done/skipped history (D-019).
            $table->softDeletes();

            $table->index(['routine_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routine_steps');
    }
};
