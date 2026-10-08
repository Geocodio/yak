<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_attachments', function (Blueprint $table) {
            $table->id();

            // The run whose message carried the file. Null while the file
            // rides on a queued steering message that has not flushed yet.
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('pending_steering_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // request | clarification_reply
            $table->string('context', 24)->default('request');

            // How the message text refers to the file, e.g. `Image #1`, so
            // `[Image #1]` in the text can be matched back to it.
            $table->string('reference', 24)->nullable();

            $table->string('disk_path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
    }
};
