<?php

namespace App;

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
}
