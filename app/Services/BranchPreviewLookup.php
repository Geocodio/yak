<?php

namespace App\Services;

use App\Models\BranchDeployment;
use App\Models\Repository;

/**
 * The live preview deployment of a branch of a repository, or null when the branch has none.
 */
class BranchPreviewLookup
{
    public function forBranch(?string $repoSlug, ?string $branchName): ?BranchDeployment
    {
        if ($repoSlug === null || $branchName === null) {
            return null;
        }

        $repository = Repository::where('slug', $repoSlug)->first();

        if ($repository === null) {
            return null;
        }

        return BranchDeployment::where('repository_id', $repository->id)
            ->where('branch_name', $branchName)
            ->whereNotIn('status', ['destroyed', 'destroying'])
            ->first();
    }
}
