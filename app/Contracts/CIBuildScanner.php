<?php

namespace App\Contracts;

use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Illuminate\Support\Collection;

interface CIBuildScanner
{
    /**
     * Fetch recent failed builds on the default branch and extract test failures.
     *
     * @return Collection<int, CIBuildFailure>
     */
    public function getRecentFailures(Repository $repository, int $maxAgeHours): Collection;
}
