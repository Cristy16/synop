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
        // One row per dose time; a vitamin taken morning and night has two (DATABASE §3.5).
        Schema::create('vitamin_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // product_id gets its own index from its foreign key. Must be a vitamin (checked by the Action).
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->time('dose_time'); // local wall-clock time (rule 9)
            $table->unsignedTinyInteger('units')->default(1); // taken off units_left when done
            $table->string('meal_note', 100)->nullable(); // "After breakfast"
            $table->timestamps();
            // Soft delete: removed doses keep their schedule and history (D-019).
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vitamin_doses');
    }
};
