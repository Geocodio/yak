<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Collection;

class ChainMediaResolver
{
    private const array TYPES = ['screenshot', 'video'];

    /**
     * @return Collection<int, Artifact>
     */
    public function forRun(YakTask $run): Collection
    {
        return Artifact::where('yak_task_id', $run->id)->whereIn('type', self::TYPES)->get();
    }

    /**
     * @param  Collection<int, YakTask>  $chain
     * @return array{artifacts: Collection<int, Artifact>, run: YakTask|null}
     */
    public function latest(Collection $chain): array
    {
        foreach ($chain->reverse() as $run) {
            $artifacts = $this->forRun($run);

            if ($artifacts->isNotEmpty()) {
                return ['artifacts' => $artifacts, 'run' => $run];
            }
        }

        return ['artifacts' => collect(), 'run' => null];
    }
}
