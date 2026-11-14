<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `public_id` keys the `files/{publicId}` link of a shared file; `shared_with_team` opens that one file to every
     * signed-in user; `anchor_sequence` is the thread event the file's turn ended at, so the card sits after that reply.
     */
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('public_id', 26)->nullable()->unique();
            $table->boolean('shared_with_team')->default(false);
            $table->timestamp('shared_at')->nullable();
            $table->unsignedBigInteger('anchor_sequence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'shared_with_team', 'shared_at', 'anchor_sequence']);
        });
    }
};
