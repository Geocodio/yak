<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_deployments', function (Blueprint $table) {
            // The recipe the deployment last started or refreshed with, so
            // request-time readers never call GitHub to resolve it.
            $table->json('preview_manifest')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('branch_deployments', function (Blueprint $table) {
            $table->dropColumn('preview_manifest');
        });
    }
};
