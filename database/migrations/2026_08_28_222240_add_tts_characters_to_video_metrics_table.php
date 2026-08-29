<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_metrics', function (Blueprint $table): void {
            $table->unsignedInteger('tts_characters')->nullable()->after('duration_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_metrics', function (Blueprint $table): void {
            $table->dropColumn('tts_characters');
        });
    }
};
