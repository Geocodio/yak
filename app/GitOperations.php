<?php

namespace App;

use App\Models\Repository;
use App\Services\GitHubAppService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

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
     * Run a git command and throw if it fails.
     */
    private static function mustRunAsYak(string $command, ?string $path = null): ProcessResult
    {
        $result = self::runAsYak($command, $path);

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Git command failed: {$command}\n{$result->errorOutput()}");
        }

        return $result;
    }

    /**
     * Probe whether the repository's origin can be reached with current credentials.
     *
     * Runs as the yak user (where the credential helper lives) with a short
     * timeout and `-c safe.directory=*` so git doesn't refuse the repo dir
     * simply because the caller (www-data) isn't its owner.
     */
    public static function canFetch(Repository $repository, int $timeoutSeconds = 10): bool
    {
        try {
            self::ensureCredentials();
        } catch (\Throwable) {
            // Credential bootstrap failures shouldn't bubble out of a
            // health probe. If it fails, ls-remote will fail too and
            // the caller will simply see an unfetchable repo.
        }

        $command = sprintf(
            'sudo runuser -u yak -- env HOME=%s git -c safe.directory=* ls-remote --exit-code origin HEAD',
            self::YAK_HOME,
        );

        try {
            $result = Process::path($repository->path)
                ->timeout($timeoutSeconds)
                ->run($command);
        } catch (\Throwable) {
            // Missing repo path, process boot failures, timeouts — treat
            // anything unexpected as unfetchable rather than bubbling out
            // of the health check render.
            return false;
        }

        return $result->exitCode() === 0;
    }

    /**
     * Return the currently checked-out branch name for a repository.
     */
    public static function currentBranch(Repository $repository): string
    {
        $result = self::mustRunAsYak('git rev-parse --abbrev-ref HEAD', $repository->path);

        return trim($result->output());
    }

    /**
     * Assert that the repository is NOT on the default branch.
     *
     * Primary safety check before pushing — prevents commits to master from
     * being picked up if something went sideways during agent execution.
     *
     * @throws RuntimeException if HEAD is on the default branch
     */
    public static function assertNotOnDefaultBranch(Repository $repository): void
    {
        $current = self::currentBranch($repository);

        if ($current === $repository->default_branch) {
            throw new RuntimeException("Repo is on the default branch '{$current}'. Refusing to proceed to prevent committing to the wrong branch.");
        }
    }

    /**
     * Configure git globally (as the yak user) to fetch GitHub App
     * installation tokens via the `yak:git-credential` artisan command.
     *
     * We deliberately avoid writing a credential helper *file* to
     * /home/yak — doing that baked the current caller's ownership
     * into the file, and any later caller running as a different
     * user (www-data vs. root) could no longer overwrite it.
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

        // Warm the cached installation token so the credential helper
        // invocation returns fast without another GitHub App API round-trip.
        app(GitHubAppService::class)->getInstallationToken($installationId);

        $helperCommand = escapeshellarg('!php /app/artisan yak:git-credential');
        self::runAsYak("git config --global credential.https://github.com.helper {$helperCommand}");

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
     * @throws RuntimeException if the clone fails
     */
    public static function cloneRepo(string $gitUrl, string $targetPath): void
    {
        self::ensureCredentials();

        $result = self::runAsYak("git clone {$gitUrl} {$targetPath}");

        if (! $result->successful()) {
            throw new RuntimeException("Failed to clone repository: {$result->errorOutput()}");
        }
    }

    /**
     * Pull the latest changes from origin on the default branch.
     */
    public static function pullDefaultBranch(Repository $repository): void
    {
        self::mustRunAsYak("git pull origin {$repository->default_branch}", $repository->path);
    }

    /**
     * Reset the working tree to a clean state: discard uncommitted changes,
     * checkout the default branch, and remove any leftover task branches.
     *
     * @throws RuntimeException if any step fails
     */
    public static function resetWorkingTree(Repository $repository): void
    {
        self::mustRunAsYak('git reset --hard', $repository->path);
        self::mustRunAsYak('git clean -fd', $repository->path);
        self::mustRunAsYak("git checkout {$repository->default_branch}", $repository->path);
    }

    /**
     * Create a new branch from origin/{default_branch}.
     *
     * If a branch with the same name already exists locally, deletes it first
     * to guarantee a clean state — prevents silent failures that could leave
     * HEAD on the default branch.
     *
     * @throws RuntimeException if any step fails
     */
    public static function createBranch(Repository $repository, string $externalId): string
    {
        self::ensureCredentials();
        self::resetWorkingTree($repository);

        $branchName = self::branchName($externalId);
        $defaultBranch = $repository->default_branch;

        self::mustRunAsYak("git fetch origin {$defaultBranch}", $repository->path);

        // Delete any stale local branch so `checkout -b` cannot silently fail
        self::runAsYak(sprintf('git branch -D %s', escapeshellarg($branchName)), $repository->path);

        self::mustRunAsYak(sprintf('git checkout -b %s origin/%s', escapeshellarg($branchName), escapeshellarg($defaultBranch)), $repository->path);

        return $branchName;
    }

    /**
     * Push a branch to origin.
     *
     * @throws RuntimeException if the push fails
     */
    public static function pushBranch(Repository $repository, string $branchName): void
    {
        self::ensureCredentials();

        $result = self::runAsYak("git push origin {$branchName}", $repository->path);

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Git push failed: {$result->errorOutput()}");
        }
    }

    /**
     * Force push a branch to origin (used on retry).
     *
     * @throws RuntimeException if the push fails
     */
    public static function forcePushBranch(Repository $repository, string $branchName): void
    {
        self::ensureCredentials();

        $result = self::runAsYak("git push --force-with-lease origin {$branchName}", $repository->path);

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Git push --force-with-lease failed: {$result->errorOutput()}");
        }
    }

    /**
     * Post-task cleanup: checkout default branch and delete the task branch.
     */
    public static function cleanup(Repository $repository, ?string $branchName): void
    {
        self::mustRunAsYak("git checkout {$repository->default_branch}", $repository->path);

        if ($branchName !== null && $branchName !== '') {
            self::runAsYak(sprintf('git branch -D %s', escapeshellarg($branchName)), $repository->path);
        }
    }

    /**
     * Checkout an existing branch.
     *
     * @throws RuntimeException if the branch does not exist or checkout fails
     */
    public static function checkoutBranch(Repository $repository, string $branchName): void
    {
        self::mustRunAsYak(sprintf('git checkout %s', escapeshellarg($branchName)), $repository->path);
    }

    /**
     * Checkout the default branch.
     */
    public static function checkoutDefaultBranch(Repository $repository): void
    {
        self::mustRunAsYak("git checkout {$repository->default_branch}", $repository->path);
    }

    /**
     * Refresh a repository by fetching the latest from origin.
     */
    public static function refreshRepo(Repository $repository): void
    {
        self::ensureCredentials();

        self::mustRunAsYak("git fetch origin {$repository->default_branch}", $repository->path);
    }
}
