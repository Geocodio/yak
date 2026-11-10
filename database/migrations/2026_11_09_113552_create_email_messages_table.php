<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();

            // RFC Message-IDs can run to 998 characters, longer than an
            // index allows, so lookups go through a SHA-256 of the id.
            $table->char('message_id_hash', 64)->unique();
            $table->string('message_id', 998);

            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->cascadeOnDelete();

            // inbound: a forward that created a task. outbound: an
            // acknowledgment Yak sent. A later email that references
            // either is a thread follow-up and is dropped.
            $table->string('direction', 8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
