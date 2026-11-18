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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Where money leaves (expense, transfer, debt_out) or enters (income, debt_in).
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // Transfers only: the destination account.
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('type', 20); // income · expense · transfer · debt_out · debt_in
            // Always > 0; the sign comes from the type (DATABASE §1.2).
            $table->bigInteger('amount_cents');
            $table->foreignId('category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            // A calendar date as the user means it (rule 9).
            $table->date('occurred_on');
            $table->string('note')->nullable();
            // Optional link to the record that created this row (morph alias: product, debt, debt_payment, want).
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // account_id and to_account_id get their own indexes from their foreign keys.
            $table->index(['user_id', 'occurred_on']);
            $table->index(['source_type', 'source_id']);
            $table->index(['user_id', 'category_id', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
