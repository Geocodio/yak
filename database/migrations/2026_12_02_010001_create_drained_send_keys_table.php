<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drained_send_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('root_task_id');
            $table->string('send_key', 36)->index();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('message_uuid');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drained_send_keys');
    }
};
