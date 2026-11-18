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
        // Cached verdicts (DATABASE §3.7). Same inputs_hash = reuse, with no AI call.
        Schema::create('want_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('want_id')->constrained()->cascadeOnDelete();
            $table->string('verdict', 20); // buy_now · wait · skip
            $table->json('reasons'); // 2–3 short strings
            $table->string('engine', 20); // ai · rules (fallback)
            $table->json('inputs'); // the PHP-computed figures and ingredient flags that were sent
            $table->char('inputs_hash', 64); // SHA-256 of inputs
            $table->timestamps();

            $table->index(['want_id', 'inputs_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('want_analyses');
    }
};
