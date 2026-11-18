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
            // Days of the month the user gets paid, e.g. [15, 30]. Safe-to-spend looks ahead
            // to the next one (D-050). Null = not set: it uses the end of the month.
            $table->json('paydays')->nullable()->after('payments_due_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('paydays');
        });
    }
};
