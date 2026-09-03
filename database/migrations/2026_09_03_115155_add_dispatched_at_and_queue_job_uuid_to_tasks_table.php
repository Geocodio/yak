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
        Schema::table('tasks', function (Blueprint $table) {
            // Set by App\Services\AgentJobDispatcher on every agent-job
            // dispatch, alongside queue_job_uuid, so the two columns can
            // never drift. yak:reap-lost-pending uses dispatched_at as the
            // fallback signal for "how long has this been queued" when the
            // queue_job_uuid can't be resolved to a live job.
            $table->timestamp('dispatched_at')->nullable()->after('started_at');

            // The queue payload's uuid, captured at dispatch time. Survives
            // release() and is an indexed column on failed_jobs, so
            // yak:reap-lost-pending can check whether the job is still
            // queued, reserved, or failed before falling back to elapsed
            // time.
            $table->string('queue_job_uuid')->nullable()->after('dispatched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['dispatched_at', 'queue_job_uuid']);
        });
    }
};
