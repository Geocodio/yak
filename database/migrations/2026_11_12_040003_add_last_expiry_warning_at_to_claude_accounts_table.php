<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->timestamp('last_expiry_warning_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->dropColumn('last_expiry_warning_at');
        });
    }
};
