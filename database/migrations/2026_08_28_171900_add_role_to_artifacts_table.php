<?php

use App\Models\Artifact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v3 looks artifacts up by what they are, not what they are called.
     * Existing files keep their old filenames on disk, so the column is
     * backfilled from type/filename rather than requiring a rename.
     */
    public function up(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->string('role', 16)->nullable()->after('type')->index();
        });

        Artifact::backfillRoles();
    }

    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
