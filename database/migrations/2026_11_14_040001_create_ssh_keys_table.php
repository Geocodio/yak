<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('name', 120);
            $table->string('type', 64);
            $table->text('public_key');
            $table->string('fingerprint', 80)->unique();
            $table->timestamp('github_added_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
