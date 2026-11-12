<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teleports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 8);
            $table->string('state', 24);
            $table->string('session_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('running_turn', 8)->nullable();
            $table->string('visibility_choice', 16)->nullable();
            $table->text('message')->nullable();
            $table->json('progress');
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message')->nullable();
            $table->json('manifest')->nullable();
            $table->json('memory_snapshot')->nullable();
            $table->json('branch_heads')->nullable();
            $table->timestamp('last_call_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teleports');
    }
};
