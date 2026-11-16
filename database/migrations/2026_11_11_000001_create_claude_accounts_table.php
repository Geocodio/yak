<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 24);
            $table->text('secret');
            $table->string('default_model', 64)->nullable();
            $table->unsignedTinyInteger('max_concurrent_turns')->default(16);
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_accounts');
    }
};
