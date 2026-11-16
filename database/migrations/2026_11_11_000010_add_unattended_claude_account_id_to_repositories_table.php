<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->foreignId('unattended_claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unattended_claude_account_id');
        });
    }
};
