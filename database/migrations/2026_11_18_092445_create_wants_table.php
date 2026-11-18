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
        // Things the user wants to buy (DATABASE §3.7, D-049).
        Schema::create('wants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048)->nullable();
            $table->string('shop', 50)->nullable(); // "Shopee", "Lazada" (from the URL's domain)
            $table->string('title');
            $table->text('description')->nullable(); // pasted description
            $table->bigInteger('price_cents')->nullable();
            $table->string('image_url', 2048)->nullable(); // remote https URL only; no file stored
            $table->string('product_type', 20)->nullable(); // skincare · makeup · other
            $table->foreignId('expense_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            $table->text('ingredients_text')->nullable();
            $table->string('priority', 20)->default('normal'); // low · normal · high
            $table->string('status', 20)->default('wanted'); // wanted · bought · dropped
            $table->string('fetch_status', 20)->default('manual'); // pending · fetched · failed · manual
            $table->date('bought_on')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wants');
    }
};
