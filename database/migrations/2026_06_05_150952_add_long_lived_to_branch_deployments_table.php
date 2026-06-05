<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_deployments', function (Blueprint $table): void {
            $table->boolean('long_lived')->default(false)->after('dirty');
            $table->unsignedInteger('idle_timeout_minutes')->nullable()->after('long_lived');
        });

        // Backfill: existing branches whose name contains the release prefix
        // become long-lived immediately, no redeploy needed.
        $prefix = (string) config('yak.deployments.release_branch_prefix', 'release/');

        if ($prefix !== '') {
            DB::table('branch_deployments')
                ->where('branch_name', 'like', '%' . $prefix . '%')
                ->update(['long_lived' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('branch_deployments', function (Blueprint $table): void {
            $table->dropColumn(['long_lived', 'idle_timeout_minutes']);
        });
    }
};
