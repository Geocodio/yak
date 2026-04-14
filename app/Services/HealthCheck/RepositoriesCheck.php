<?php

namespace App\Services\HealthCheck;

use App\GitOperations;
use App\Models\Repository;

class RepositoriesCheck implements HealthCheck
{
    public function id(): string
    {
        return 'repositories';
    }

    public function name(): string
    {
        return 'Repositories Fetchable';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $repos = Repository::where('is_active', true)->get();

        if ($repos->isEmpty()) {
            return HealthResult::ok('No active repositories');
        }

        $total = $repos->count();
        $fetchable = 0;
        $failures = [];

        foreach ($repos as $repo) {
            try {
                $ok = GitOperations::canFetch($repo);
            } catch (\Throwable) {
                $failures[] = $repo->slug;

                continue;
            }

            if ($ok) {
                $fetchable++;
            } else {
                $failures[] = $repo->slug;
            }
        }

        if ($fetchable === $total) {
            return HealthResult::ok("{$fetchable}/{$total} active repositories OK");
        }

        return HealthResult::error(
            "{$fetchable}/{$total} OK — failed: " . implode(', ', $failures),
        );
    }
}
