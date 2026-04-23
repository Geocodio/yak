<?php

namespace App\Jobs\Deployments;

use App\DataTransferObjects\TemplateSnapshotRef;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GarbageCollectTemplateSnapshotsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(): void
    {
        $all = $this->listAllSnapshots();
        $pinned = $this->computePinnedSet();

        foreach ($all as $ref) {
            if ($pinned->contains($ref->name())) {
                continue;
            }

            $result = Process::run("incus snapshot delete {$ref->name()}");
            if (! $result->successful()) {
                Log::channel('yak')->warning('Failed to GC template snapshot', [
                    'snapshot' => $ref->name(),
                    'stderr' => $result->errorOutput(),
                ]);
            }
        }
    }

    /** @return list<TemplateSnapshotRef> */
    private function listAllSnapshots(): array
    {
        $result = Process::run('incus snapshot list --format plain');
        if (! $result->successful()) {
            return [];
        }

        return collect(preg_split('/\r?\n/', trim($result->output())))
            ->map(fn ($line) => TemplateSnapshotRef::parse(trim($line)))
            ->filter()
            ->values()
            ->all();
    }

    private function computePinnedSet(): Collection
    {
        $pinned = collect();

        Repository::query()
            ->whereNotNull('current_template_version')
            ->where('current_template_version', '>', 0)
            ->get(['slug', 'current_template_version'])
            ->each(fn ($r) => $pinned->push(
                (new TemplateSnapshotRef($r->slug, (int) $r->current_template_version))->name()
            ));

        BranchDeployment::query()
            ->whereNotIn('status', [
                DeploymentStatus::Destroyed->value,
                DeploymentStatus::Destroying->value,
            ])
            ->with('repository:id,slug')
            ->get(['id', 'repository_id', 'template_version'])
            ->each(fn ($d) => $pinned->push(
                (new TemplateSnapshotRef($d->repository->slug, (int) $d->template_version))->name()
            ));

        return $pinned->unique();
    }
}
