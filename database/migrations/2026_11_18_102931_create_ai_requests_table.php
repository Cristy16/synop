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
        // One row per real AI provider call: the daily limit and an audit. No request or
        // response content is stored (privacy, PRD §5; DATABASE §3.7). Cache hits aren't logged.
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // ingredient_scan · want_verdict
            $table->date('requested_on'); // the user's local date; the limit is 10 per day
            $table->boolean('succeeded');
            $table->timestamp('created_at')->nullable(); // no updated_at

            $table->index(['user_id', 'requested_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
