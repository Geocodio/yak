<?php

namespace App;

use App\Services\IncusSandboxManager;
use RuntimeException;

/**
 * Static helpers for git-related work that runs in the yak app container.
 *
 * Repos no longer live on the host — the agent clones, branches, and pushes
 * inside its Incus sandbox via `IncusSandboxManager::run()`. The only piece
 * the host still owns is naming the branch consistently, which jobs use to
 * tell the sandbox what branch to create.
 */
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
     * Return the first branch name not already present on the remote.
     *
     * If the base name is free, it's returned as-is. Otherwise a counter
     * suffix (`-2`, `-3`, ...) is appended until an unused name is found.
     * This prevents retried tasks from clobbering branches pushed by
     * previous attempts.
     *
     * @param  callable(string): bool  $existsOnRemote  Tests whether a given branch exists on the remote.
     */
    public static function resolveAvailableBranchName(string $baseName, callable $existsOnRemote, int $maxAttempts = 100): string
    {
        if (! $existsOnRemote($baseName)) {
            return $baseName;
        }

        for ($counter = 2; $counter <= $maxAttempts; $counter++) {
            $candidate = "{$baseName}-{$counter}";

            if (! $existsOnRemote($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Unable to find an available branch name for '{$baseName}' within {$maxAttempts} attempts.");
    }

    /**
     * Returns true when HEAD inside the sandbox has at least one commit
     * ahead of `origin/{defaultBranch}`. Used to distinguish "Claude
     * actually wrote code" from "Claude answered without changing files".
     */
    public static function hasNewCommits(
        IncusSandboxManager $sandbox,
        string $containerName,
        string $workspacePath,
        string $defaultBranch,
    ): bool {
        $result = $sandbox->run(
            $containerName,
            "cd {$workspacePath} && git rev-list --count origin/{$defaultBranch}..HEAD",
            timeout: 15,
        );

        if ($result->exitCode() !== 0) {
            throw new RuntimeException(
                "hasNewCommits failed (exit {$result->exitCode()}): " . $result->errorOutput()
            );
        }

        return (int) trim($result->output()) > 0;
    }

    /**
     * Returns true when the sandbox working tree has uncommitted changes.
     *
     * Paired with hasNewCommits to catch the "agent edited files but
     * forgot to commit" failure mode. Yak's global gitignore excludes
     * .yak-artifacts/, so capture files never show up here.
     */
    public static function hasUncommittedChanges(
        IncusSandboxManager $sandbox,
        string $containerName,
        string $workspacePath,
    ): bool {
        $result = $sandbox->run(
            $containerName,
            "cd {$workspacePath} && git status --porcelain",
            timeout: 15,
        );

        if ($result->exitCode() !== 0) {
            throw new RuntimeException(
                "hasUncommittedChanges failed (exit {$result->exitCode()}): " . $result->errorOutput()
            );
        }

        return trim($result->output()) !== '';
    }
}
