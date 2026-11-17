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
            // IANA timezone name, e.g. "Asia/Manila". Used to work out the user's "today".
            $table->string('timezone', 64)->default('Asia/Manila')->after('password');
            // ISO 4217 currency code. Display only in the MVP.
            $table->char('currency', 3)->default('PHP')->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'currency']);
        });
    }
};
