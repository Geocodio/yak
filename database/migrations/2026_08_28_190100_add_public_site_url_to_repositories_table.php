<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The origin the walkthrough's browser bar shows (spec §10). It is a
     * property of the repository, not of the installation's brand, so it
     * lives here rather than on the video theme row. No UI yet — Wave 3
     * adds the field to the repository settings page.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            if (! Schema::hasColumn('repositories', 'public_site_url')) {
                $table->string('public_site_url')->nullable()->after('default_branch');
            }
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('public_site_url');
        });
    }
};
