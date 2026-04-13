<?php

namespace App;

use App\Models\Repository;
use App\Services\GitHubAppService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class GitOperations
{
    private static bool $credentialsConfigured = false;

    private const YAK_HOME = '/home/yak';

    /**
     * Run a command as the yak user.
     *
     * The queue worker runs as www-data, but all git/repo filesystem
     * operations must run as yak (the sandboxed repo owner).
     */
    private static function runAsYak(string $command, ?string $path = null): ProcessResult
    {
        $wrapped = sprintf(
            'sudo runuser -u yak -- env HOME=%s %s',
            self::YAK_HOME,
            $command,
        );

        return $path
            ? Process::path($path)->run($wrapped)
            : Process::run($wrapped);
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

        $helperPath = self::YAK_HOME . '/.git-credential-yak';
        file_put_contents($helperPath, "#!/bin/sh\necho username=x-access-token\necho password={$token}\n");
        chmod($helperPath, 0755);

        self::runAsYak("git config --global credential.https://github.com.helper {$helperPath}");

        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');

        self::runAsYak(sprintf('git config --global user.name %s', escapeshellarg($gitName)));
        self::runAsYak(sprintf('git config --global user.email %s', escapeshellarg($gitEmail)));

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
     * Clone a repository into the target path.
     *
     * @throws \RuntimeException if the clone fails
     */
    public static function cloneRepo(string $gitUrl, string $targetPath): void
    {
        self::ensureCredentials();

        $result = self::runAsYak("git clone {$gitUrl} {$targetPath}");

        if (! $result->successful()) {
            throw new \RuntimeException("Failed to clone repository: {$result->errorOutput()}");
        }
    }

    /**
     * Pull the latest changes from origin on the default branch.
     */
    public static function pullDefaultBranch(Repository $repository): void
    {
        self::runAsYak("git pull origin {$repository->default_branch}", $repository->path);
    }

    /**
     * Reset the working tree to a clean state: discard uncommitted changes,
     * checkout the default branch, and remove any leftover task branches.
     */
    public static function resetWorkingTree(Repository $repository): void
    {
        self::runAsYak('git reset --hard', $repository->path);
        self::runAsYak('git clean -fd', $repository->path);
        self::runAsYak("git checkout {$repository->default_branch}", $repository->path);
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

        self::runAsYak("git fetch origin {$defaultBranch}", $repository->path);
        self::runAsYak("git checkout -b {$branchName} origin/{$defaultBranch}", $repository->path);

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

        $result = self::runAsYak("git push origin {$branchName}", $repository->path);

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

        $result = self::runAsYak("git push --force-with-lease origin {$branchName}", $repository->path);

        if ($result->exitCode() !== 0) {
            throw new \RuntimeException("Git push --force-with-lease failed: {$result->errorOutput()}");
        }
    }

    /**
     * Post-task cleanup: checkout default branch and delete the task branch.
     */
    public static function cleanup(Repository $repository, ?string $branchName): void
    {
        self::runAsYak("git checkout {$repository->default_branch}", $repository->path);

        if ($branchName !== null && $branchName !== '') {
            self::runAsYak("git branch -D {$branchName}", $repository->path);
        }
    }

    /**
     * Checkout an existing branch.
     */
    public static function checkoutBranch(Repository $repository, string $branchName): void
    {
        self::runAsYak("git checkout {$branchName}", $repository->path);
    }

    /**
     * Checkout the default branch.
     */
    public static function checkoutDefaultBranch(Repository $repository): void
    {
        self::runAsYak("git checkout {$repository->default_branch}", $repository->path);
    }

    /**
     * Refresh a repository by fetching the latest from origin.
     */
    public static function refreshRepo(Repository $repository): void
    {
        self::ensureCredentials();

        self::runAsYak("git fetch origin {$repository->default_branch}", $repository->path);
    }
}
