<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('os', 16);
            $table->string('arch', 16);
            $table->string('cli_version', 32)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('last_teleport_thread_id')->nullable()->constrained('threads')->nullOnDelete();
            $table->timestamp('last_teleport_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
