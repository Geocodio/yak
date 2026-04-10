<?php

namespace App;

use App\Models\Repository;
use Illuminate\Support\Facades\Process;

class GitOperations
{
    /**
     * Generate a safe git branch name from an external ID.
     *
     * Sanitizes special characters that are invalid in git branch names.
     */
    public static function branchName(string $externalId): string
    {
        $name = $externalId;

        // Replace characters invalid in git branch names with hyphens
        $name = (string) preg_replace('/[\s~^:?*\[\]\\\\@{}<>|!"\'`#$%&()+=,;]/', '-', $name);

        // Collapse consecutive dots (git disallows "..")
        $name = (string) preg_replace('/\.{2,}/', '.', $name);

        // Collapse consecutive hyphens
        $name = (string) preg_replace('/-{2,}/', '-', $name);

        // Collapse consecutive slashes
        $name = (string) preg_replace('/\/{2,}/', '/', $name);

        // Remove leading/trailing hyphens, dots, and slashes
        $name = trim($name, '-./');

        // Remove ".lock" suffix (git disallows branch names ending in .lock)
        if (str_ends_with($name, '.lock')) {
            $name = substr($name, 0, -5);
        }

        // Fallback for empty result
        if ($name === '') {
            $name = 'task';
        }

        return "yak/{$name}";
    }

    /**
     * Create a new branch from origin/{default_branch}.
     */
    public static function createBranch(Repository $repository, string $externalId): string
    {
        $branchName = self::branchName($externalId);
        $defaultBranch = $repository->default_branch;

        Process::path($repository->path)
            ->run("git fetch origin {$defaultBranch}");

        Process::path($repository->path)
            ->run("git checkout -b {$branchName} origin/{$defaultBranch}");

        return $branchName;
    }

    /**
     * Push a branch to origin.
     */
    public static function pushBranch(Repository $repository, string $branchName): void
    {
        Process::path($repository->path)
            ->run("git push origin {$branchName}");
    }

    /**
     * Force push a branch to origin (used on retry).
     */
    public static function forcePushBranch(Repository $repository, string $branchName): void
    {
        Process::path($repository->path)
            ->run("git push --force origin {$branchName}");
    }

    /**
     * Post-task cleanup: checkout default branch and delete the task branch.
     */
    public static function cleanup(Repository $repository, ?string $branchName): void
    {
        Process::path($repository->path)
            ->run("git checkout {$repository->default_branch}");

        if ($branchName !== null && $branchName !== '') {
            Process::path($repository->path)
                ->run("git branch -D {$branchName}");
        }
    }

    /**
     * Checkout an existing branch.
     */
    public static function checkoutBranch(Repository $repository, string $branchName): void
    {
        Process::path($repository->path)
            ->run("git checkout {$branchName}");
    }

    /**
     * Checkout the default branch.
     */
    public static function checkoutDefaultBranch(Repository $repository): void
    {
        Process::path($repository->path)
            ->run("git checkout {$repository->default_branch}");
    }

    /**
     * Refresh a repository by fetching the latest from origin.
     */
    public static function refreshRepo(Repository $repository): void
    {
        Process::path($repository->path)
            ->run("git fetch origin {$repository->default_branch}");
    }
}
