<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fills in how long each finished thread turn ran and how many steps it took, from the times and the
     * assistant turn count every turn already stores.
     */
    public function up(): void
    {
        DB::table('task_runs')
            ->whereNotNull('thread_id')
            ->whereNotNull('finished_at')
            ->whereNull('total_ms')
            ->select(['id', 'started_at', 'agent_started_at', 'finished_at', 'assistant_turns'])
            ->chunkById(500, function ($runs): void {
                foreach ($runs as $run) {
                    $startedAt = CarbonImmutable::parse($run->agent_started_at ?? $run->started_at);

                    DB::table('task_runs')->where('id', $run->id)->update([
                        'total_ms' => (int) $startedAt->diffInMilliseconds(CarbonImmutable::parse($run->finished_at), true),
                        'num_turns' => (int) $run->assistant_turns,
                    ]);
                }
            });
    }
};
