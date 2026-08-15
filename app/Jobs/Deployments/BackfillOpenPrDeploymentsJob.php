<?php

namespace App\Jobs\Deployments;

use App\Actions\BackfillOpenPrDeployments;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Periodic reconciler: scans deployment-eligible repositories for open PRs
 * that have no branch deployment yet and provisions them.
 *
 * Closes the gap where a PR opened before its repo finished setup (or before
 * deployments were enabled) never fires another `opened` webhook, so the
 * deployment would otherwise never be created.
 */
class BackfillOpenPrDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(BackfillOpenPrDeployments $backfill): void
    {
        Repository::query()
            ->where('deployments_enabled', true)
            ->where('setup_status', 'ready')
            ->where('current_template_version', '>=', 1)
            ->get()
            ->each(function (Repository $repository) use ($backfill): void {
                try {
                    $created = $backfill($repository);

                    if ($created > 0) {
                        Log::info('Backfilled open-PR deployments', [
                            'repository_id' => $repository->id,
                            'repository_slug' => $repository->slug,
                            'created' => $created,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // One repo's GitHub/API failure must not abort the sweep.
                    Log::warning('Open-PR deployment backfill failed for repo', [
                        'repository_id' => $repository->id,
                        'repository_slug' => $repository->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
