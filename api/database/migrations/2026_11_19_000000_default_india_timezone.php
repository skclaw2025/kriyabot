<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Kolkata')->change();
        });
        // Accounts created before this fix were saved as UTC by mistake.
        DB::table('users')->whereIn('timezone', ['UTC', 'Asia/Calcutta'])->update(['timezone' => 'Asia/Kolkata']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->change();
        });
    }
};
