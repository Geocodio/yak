<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_walkthroughs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('anchor_sequence')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->json('chapters')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'number']);
        });

        Schema::table('artifacts', function (Blueprint $table) {
            $table->foreignId('thread_walkthrough_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_walkthrough_id');
        });

        Schema::dropIfExists('thread_walkthroughs');
    }
};
