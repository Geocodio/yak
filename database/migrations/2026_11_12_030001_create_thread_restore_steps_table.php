<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_restore_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('step', 32);
            $table->string('status', 16)->default('pending');
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_restore_steps');
    }
};
