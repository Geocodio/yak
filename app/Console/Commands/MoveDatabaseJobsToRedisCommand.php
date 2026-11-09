<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

#[Signature('yak:queue:move-database-jobs')]
#[Description('Move jobs still waiting on the database queue onto the Redis queue')]
class MoveDatabaseJobsToRedisCommand extends Command
{
    /**
     * Workers read only Redis, so a job left in the `jobs` table is invisible
     * to them and to yak:reap-lost-pending, which would re-dispatch its task
     * and later run it twice. The deploy runs this right after workers
     * resume; with an empty table it does nothing.
     *
     * Reserved rows are moved too: the worker that held one died with the
     * old container. The row's attempt count is carried into the payload,
     * which is where the Redis queue keeps it.
     */
    public function handle(): int
    {
        $moved = 0;

        foreach (DB::table('jobs')->orderBy('id')->get() as $job) {
            $payload = json_decode((string) $job->payload, true);

            if (! is_array($payload)) {
                $this->components->warn("Skipped job {$job->id}: its payload is not JSON.");

                continue;
            }

            $payload['attempts'] = (int) $job->attempts;

            Queue::connection('redis')->pushRaw((string) json_encode($payload), (string) $job->queue);
            DB::table('jobs')->where('id', $job->id)->delete();
            $moved++;
        }

        $this->components->info("Moved {$moved} job(s) from the database queue to Redis.");

        return self::SUCCESS;
    }
}
