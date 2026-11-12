<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('yak:mark-deployments-gone {--dry-run : Report what would change without writing}')]
#[Description('Mark branch deployments whose incus container no longer exists as destroyed')]
class MarkDeploymentsGoneCommand extends Command
{
    public function handle(): int
    {
        $listing = Process::timeout(30)->run('incus list --format csv -c n');

        if ($listing->failed()) {
            $this->components->error('incus list failed, nothing marked: ' . trim($listing->errorOutput()));

            return self::FAILURE;
        }

        $containerNames = array_values(array_filter(array_map('trim', explode("\n", $listing->output()))));
        $isDryRun = (bool) $this->option('dry-run');
        $countsByState = [];

        BranchDeployment::withoutGlobalScopes()
            ->whereNotIn('status', [DeploymentStatus::Destroyed->value, DeploymentStatus::Destroying->value])
            ->where('container_name', '!=', '')
            ->whereNotNull('container_name')
            ->whereNotIn('container_name', $containerNames)
            ->chunkById(500, function ($deployments) use (&$countsByState, $isDryRun): void {
                foreach ($deployments->groupBy(fn (BranchDeployment $deployment) => $deployment->status->value) as $state => $group) {
                    $countsByState[$state] = ($countsByState[$state] ?? 0) + $group->count();
                }

                // Destroyed is a final state, so a raw update bypasses the
                // state machine and the observer (no GitHub sync is sent).
                if (! $isDryRun) {
                    BranchDeployment::withoutGlobalScopes()
                        ->whereIn('id', $deployments->modelKeys())
                        ->update(['status' => DeploymentStatus::Destroyed->value]);
                }
            });

        $this->table(
            ['state', $isDryRun ? 'would mark' : 'marked'],
            collect($countsByState)->map(fn (int $count, string $state) => [$state, $count])->values()->all(),
        );
        $this->line('Total: ' . array_sum($countsByState));

        return self::SUCCESS;
    }
}
