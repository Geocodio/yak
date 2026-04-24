<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->longText('message')->change();
            $table->string('phase')->nullable()->after('level');
            $table->index(['branch_deployment_id', 'phase', 'created_at'], 'deployment_logs_branch_phase_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->dropIndex('deployment_logs_branch_phase_created_idx');
            $table->dropColumn('phase');
            $table->text('message')->change();
        });
    }
};
