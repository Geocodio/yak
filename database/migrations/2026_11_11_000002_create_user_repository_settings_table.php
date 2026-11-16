<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_repository_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('repo');
            $table->foreignId('claude_account_id')->nullable()->constrained('claude_accounts')->nullOnDelete();
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_repository_settings');
    }
};
