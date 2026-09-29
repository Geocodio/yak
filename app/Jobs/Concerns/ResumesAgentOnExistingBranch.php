<?php

namespace App\Jobs\Concerns;

use App\Models\Repository;
use App\Services\IncusSandboxManager;

trait ResumesAgentOnExistingBranch
{
    /**
     * Configure git, refresh the default branch, then fetch + checkout the
     * existing task branch. Never creates a branch — the branch already
     * exists from the original run.
     */
    protected function prepareExistingBranch(
        IncusSandboxManager $sandbox,
        string $containerName,
        Repository $repository,
        string $branchName,
    ): void {
        $workspacePath = IncusSandboxManager::workspacePath();

        $sandbox->configureGitIdentity($containerName);
        $sandbox->injectGitCredentials($containerName);

        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$repository->default_branch}", timeout: 60);
        $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
        $sandbox->run($containerName, "cd {$workspacePath} && git checkout {$branchName}", timeout: 30);
    }

    /**
     * Refuse-to-push-on-default-branch safety check, refresh the credential
     * helper (the token may have expired during a long run), then force-push
     * with lease.
     */
    protected function pushExistingBranch(
        IncusSandboxManager $sandbox,
        string $containerName,
        Repository $repository,
        string $branchName,
    ): void {
        $workspacePath = IncusSandboxManager::workspacePath();

        $branchResult = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
        $currentBranch = trim($branchResult->output());

        if ($currentBranch === $repository->default_branch) {
            throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
        }

        $sandbox->injectGitCredentials($containerName);

        $pushResult = $sandbox->run($containerName, "cd {$workspacePath} && git push --force-with-lease origin {$branchName}", timeout: 60);

        if ($pushResult->exitCode() !== 0) {
            throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
        }
    }

    /**
     * Push new commits onto a branch a person owns. Their pushes during the run
     * are rebased under Yak's commits, never overwritten: a conflict aborts the
     * rebase and a rejected push fails, and neither ever forces.
     */
    protected function pushOntoExternalBranch(
        IncusSandboxManager $sandbox,
        string $containerName,
        Repository $repository,
        string $branchName,
    ): void {
        $workspacePath = IncusSandboxManager::workspacePath();

        $currentBranch = trim($sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10)->output());

        if ($currentBranch === $repository->default_branch) {
            throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
        }

        $sandbox->injectGitCredentials($containerName);

        $rebase = $sandbox->run($containerName, "cd {$workspacePath} && git pull --rebase origin {$branchName}", timeout: 120);

        if ($rebase->exitCode() !== 0) {
            $sandbox->run($containerName, "cd {$workspacePath} && git rebase --abort", timeout: 30);

            throw new \RuntimeException("The branch {$branchName} changed while I was working, and my commits no longer apply cleanly.");
        }

        $push = $sandbox->run($containerName, "cd {$workspacePath} && git push origin HEAD:{$branchName}", timeout: 60);

        if ($push->exitCode() !== 0) {
            throw new \RuntimeException("GitHub rejected the push to {$branchName}: {$push->errorOutput()}");
        }
    }
}
