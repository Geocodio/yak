<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_file_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 1024);
            $table->char('content_sha256', 64);
            $table->foreignId('pending_steering_message_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('noted_at')->nullable();
            $table->timestamps();

            $table->index(['thread_id', 'noted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_file_edits');
    }
};
