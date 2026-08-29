<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `caption` carries the agent-written line for a v3 screenshot (spec
     * §8b); `public_token` keys the permanent unsigned image route so a
     * GIF or poster embedded in a PR body does not rot after the signed
     * URL's seven days (spec §8). Both are guarded so a re-run after a
     * partial DDL commit on MariaDB is safe.
     */
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('artifacts', 'caption')) {
                $table->text('caption')->nullable()->after('filename');
            }
            if (! Schema::hasColumn('artifacts', 'public_token')) {
                $table->string('public_token', 26)->nullable()->unique()->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['caption', 'public_token']);
        });
    }
};
