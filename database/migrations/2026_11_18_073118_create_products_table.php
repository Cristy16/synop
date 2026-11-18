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
        // Skincare, makeup and vitamins (DATABASE §3.5).
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // skincare · makeup · vitamin
            $table->string('brand', 100)->nullable();
            $table->string('name', 150);
            $table->string('category', 50)->nullable(); // free text: "serum", "sunscreen", "lip tint"
            $table->bigInteger('price_cents')->nullable(); // what was paid for this entry
            // Calendar dates as the user means them (rule 9).
            $table->date('purchased_on')->nullable();
            $table->date('opened_on')->nullable();
            $table->unsignedTinyInteger('pao_months')->nullable(); // period after opening
            $table->date('printed_expiry_on')->nullable();
            // Recomputed on save: the earlier of printed_expiry_on and opened_on + pao_months (D-016).
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('status', 20)->default('unopened'); // unopened · in_use · finished · discarded
            $table->text('ingredients_text')->nullable();
            // Vitamins only.
            $table->unsignedInteger('units_left')->nullable();
            $table->unsignedInteger('low_stock_threshold')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type', 'status']);
            $table->index(['user_id', 'expires_on']);
            $table->index(['user_id', 'type', 'purchased_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
