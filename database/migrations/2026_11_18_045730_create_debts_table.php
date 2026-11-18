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
        Schema::create('debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('direction', 20); // owed_to_me · i_owe
            $table->bigInteger('principal_cents');
            $table->string('description')->nullable();
            // The account the principal left or entered. Null = a debt from before using the app.
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('started_on');
            // One-off due date. Null for installment debts (Phase 4) and debts with no deadline.
            $table->date('due_on')->nullable();
            // Installment debts only (Phase 4): amount due per occurrence of its schedule rule.
            $table->bigInteger('installment_cents')->nullable();
            $table->string('status', 20)->default('open'); // open · settled
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // contact_id gets its own index from its foreign key.
            $table->index(['user_id', 'status', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
