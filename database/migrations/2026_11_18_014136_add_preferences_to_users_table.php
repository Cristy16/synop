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
        Schema::table('users', function (Blueprint $table) {
            // Money kept aside when working out safe-to-spend, in centavos.
            $table->bigInteger('safety_buffer_cents')->default(0)->after('currency');
            // Today's "Payments due" shows debts due within this many days, plus overdue.
            $table->unsignedTinyInteger('payments_due_days')->default(7)->after('safety_buffer_cents');
            // Local time for the web-push morning summary (Phase 8). Null = off.
            $table->time('morning_summary_time')->nullable()->after('payments_due_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['safety_buffer_cents', 'payments_due_days', 'morning_summary_time']);
        });
    }
};
