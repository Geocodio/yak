<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->timestamp('usage_limit_resets_at')->nullable();
            $table->decimal('usage_utilisation', 5, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('claude_accounts', function (Blueprint $table) {
            $table->dropColumn(['usage_limit_resets_at', 'usage_utilisation']);
        });
    }
};
