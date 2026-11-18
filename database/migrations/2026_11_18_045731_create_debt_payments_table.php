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
        Schema::create('debt_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // debt_id gets its own index from its foreign key.
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            // Paid from (i_owe) or into (owed_to_me) this account.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_cents'); // > 0 and ≤ remaining, checked by the Action
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
