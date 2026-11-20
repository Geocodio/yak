<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shared file keeps its row and link when Claude saves it again; `content_hash` tells whether the content changed,
     * and each change adds a version at the thread event it happened after.
     */
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable();
        });

        Schema::create('artifact_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artifact_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('anchor_sequence')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['artifact_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifact_versions');

        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropColumn('content_hash');
        });
    }
};
