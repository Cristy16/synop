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
        // Which product has which tag, worked out from its ingredients. No user_id: both
        // sides are user-owned and the Action checks they share a user (DATABASE §3.5).
        Schema::create('ingredient_tag_product', function (Blueprint $table) {
            $table->foreignId('ingredient_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['ingredient_tag_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_tag_product');
    }
};
