<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class ChainMediaResolver
{
    private const array TYPES = ['screenshot', 'video'];

    /**
     * @return Collection<int, Artifact>
     */
    public function forRun(YakTask $run): Collection
    {
        return Artifact::where('yak_task_id', $run->id)
            ->whereIn('type', self::TYPES)
            // `role` is nullable for rows written before the column existed,
            // and SQL `NULL NOT IN (...)` is NULL, so a bare whereNotIn would
            // drop every legacy screenshot along with the render inputs.
            ->where(function (Builder $query): void {
                $query->whereNull('role')
                    ->orWhereNotIn('role', Artifact::RENDER_INPUT_ROLES);
            })
            ->get();
    }

    /**
     * @param  SupportCollection<int, YakTask>  $chain
     * @return array{artifacts: Collection<int, Artifact>, run: YakTask|null}
     */
    public function latest(SupportCollection $chain): array
    {
        foreach ($chain->reverse() as $run) {
            $artifacts = $this->forRun($run);

            if ($artifacts->isNotEmpty()) {
                return ['artifacts' => $artifacts, 'run' => $run];
            }
        }

        return ['artifacts' => new Collection, 'run' => null];
    }
}
