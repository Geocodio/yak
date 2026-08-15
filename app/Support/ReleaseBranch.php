<?php

namespace App\Support;

final class ReleaseBranch
{
    /**
     * Whether a branch name should be auto-treated as long-lived because
     * it contains the configured release prefix (default "release/").
     */
    public static function matches(string $branchName): bool
    {
        $prefix = (string) config('yak.deployments.release_branch_prefix', 'release/');

        return $prefix !== '' && str_contains($branchName, $prefix);
    }
}
