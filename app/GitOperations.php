<?php

namespace App;

use App\Models\Repository;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Process;

class GitOperations
{
    private static bool $credentialsConfigured = false;

    /**
     * Get the home directory for the current effective user.
     *
     * Supervisor sets user=yak but inherits HOME=/root from the container
     * environment, so we resolve it from the passwd entry instead.
     */
    private static function homeDir(): string
    {
        return posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp';
    }

    /**
     * Configure git credentials using the GitHub App installation token.
     *
     * Writes a credential helper script and sets it globally so all git
     * operations (clone, fetch, push) authenticate automatically. Only
     * runs once per process.
     */
    public static function ensureCredentials(): void
    {
        if (self::$credentialsConfigured) {
            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return;
        }

        $token = app(GitHubAppService::class)->getInstallationToken($installationId);

        $helperPath = self::homeDir() . '/.git-credential-yak';
        file_put_contents($helperPath, "#!/bin/sh\necho username=x-access-token\necho password={$token}\n");
        chmod($helperPath, 0755);

        Process::env(['HOME' => self::homeDir()])
            ->run("git config --global credential.https://github.com.helper {$helperPath}");

        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');

        Process::env(['HOME' => self::homeDir()])
            ->run(sprintf('git config --global user.name %s', escapeshellarg($gitName)));

        Process::env(['HOME' => self::homeDir()])
            ->run(sprintf('git config --global user.email %s', escapeshellarg($gitEmail)));

        self::$credentialsConfigured = true;
    }

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
     * Reset the working tree to a clean state: discard uncommitted changes,
     * checkout the default branch, and remove any leftover task branches.
     */
    public static function resetWorkingTree(Repository $repository): void
    {
        $env = ['HOME' => self::homeDir()];

        Process::path($repository->path)->env($env)
            ->run('git reset --hard');

        Process::path($repository->path)->env($env)
            ->run('git clean -fd');

        Process::path($repository->path)->env($env)
            ->run("git checkout {$repository->default_branch}");
    }

    /**
     * Create a new branch from origin/{default_branch}.
     */
    public static function createBranch(Repository $repository, string $externalId): string
    {
        self::ensureCredentials();
        self::resetWorkingTree($repository);

        $branchName = self::branchName($externalId);
        $defaultBranch = $repository->default_branch;

        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git fetch origin {$defaultBranch}");

        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git checkout -b {$branchName} origin/{$defaultBranch}");

        return $branchName;
    }

    /**
     * Push a branch to origin.
     *
     * @throws \RuntimeException if the push fails
     */
    public static function pushBranch(Repository $repository, string $branchName): void
    {
        self::ensureCredentials();

        $result = Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git push origin {$branchName}");

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException("Git push failed: {$result->errorOutput()}");
        }
    }

    /**
     * Force push a branch to origin (used on retry).
     *
     * @throws \RuntimeException if the push fails
     */
    public static function forcePushBranch(Repository $repository, string $branchName): void
    {
        self::ensureCredentials();

        $result = Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git push --force-with-lease origin {$branchName}");

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException("Git push --force-with-lease failed: {$result->errorOutput()}");
        }
    }

    /**
     * Post-task cleanup: checkout default branch and delete the task branch.
     */
    public static function cleanup(Repository $repository, ?string $branchName): void
    {
        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git checkout {$repository->default_branch}");

        if ($branchName !== null && $branchName !== '') {
            Process::path($repository->path)
                ->env(['HOME' => self::homeDir()])
                ->run("git branch -D {$branchName}");
        }
    }

    /**
     * Checkout an existing branch.
     */
    public static function checkoutBranch(Repository $repository, string $branchName): void
    {
        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git checkout {$branchName}");
    }

    /**
     * Checkout the default branch.
     */
    public static function checkoutDefaultBranch(Repository $repository): void
    {
        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git checkout {$repository->default_branch}");
    }

    /**
     * Refresh a repository by fetching the latest from origin.
     */
    public static function refreshRepo(Repository $repository): void
    {
        self::ensureCredentials();

        Process::path($repository->path)
            ->env(['HOME' => self::homeDir()])
            ->run("git fetch origin {$repository->default_branch}");
    }
}
