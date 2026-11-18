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
        // School terms (DATABASE §3.6, D-048).
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100); // "1st Sem AY 2026–2027"
            $table->date('starts_on');
            $table->date('ends_on');
            // One active per user; the Action turns the others off in the same transaction.
            $table->boolean('is_active')->default(false);
            // Archived semesters' classes don't appear in Today.
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
