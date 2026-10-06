<?php

namespace App\Jobs\Concerns;

use App\Exceptions\ExternalBranchPushException;
use App\Models\Repository;
use App\Services\IncusSandboxManager;

trait ResumesAgentOnExistingBranch
{
    /**
     * Configure git, refresh the default branch, then check out the task
     * branch, creating it from the default branch when it was never pushed.
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
        $escapedBranch = escapeshellarg($branchName);
        $lookup = $sandbox->run($containerName, "cd {$workspacePath} && git ls-remote --exit-code --heads origin {$escapedBranch}", timeout: 30);

        // Exit 2 means the remote has no such branch: a run that stopped to ask questions never pushed it.
        if ($lookup->exitCode() === 2) {
            $checkout = "git checkout -b {$branchName} origin/{$repository->default_branch}";
        } elseif ($lookup->exitCode() === 0) {
            $sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$branchName}", timeout: 60);
            $checkout = "git checkout {$branchName}";
        } else {
            throw new \RuntimeException("Could not check whether branch '{$branchName}' exists on the remote: {$lookup->errorOutput()}");
        }

        $sandbox->run($containerName, "cd {$workspacePath} && {$checkout}", timeout: 30);
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
     * Push new commits onto a branch a person owns. Uncommitted work is
     * discarded (only commits are pushed). Their pushes during the run are
     * rebased under Yak's commits, never overwritten: a conflict aborts the
     * rebase and a rejected push fails, and neither ever forces.
     *
     * @throws ExternalBranchPushException
     */
    protected function pushOntoExternalBranch(
        IncusSandboxManager $sandbox,
        string $containerName,
        Repository $repository,
        string $branchName,
    ): void {
        $workspacePath = IncusSandboxManager::workspacePath();
        $escapedBranch = escapeshellarg($branchName);
        $cannotUpdate = new ExternalBranchPushException("I couldn't update the branch `{$branchName}` before pushing.");

        $currentBranch = trim($sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10)->output());

        if ($currentBranch === $repository->default_branch) {
            throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
        }

        $sandbox->injectGitCredentials($containerName);

        if ($sandbox->run($containerName, "cd {$workspacePath} && git reset --hard HEAD && git clean -fd", timeout: 60)->exitCode() !== 0) {
            throw $cannotUpdate;
        }

        if ($sandbox->run($containerName, "cd {$workspacePath} && git fetch origin {$escapedBranch}", timeout: 60)->exitCode() !== 0) {
            throw $cannotUpdate;
        }

        $remoteRef = escapeshellarg("origin/{$branchName}");
        $ancestorExitCode = $sandbox->run($containerName, "cd {$workspacePath} && git merge-base --is-ancestor {$remoteRef} HEAD", timeout: 30)->exitCode();

        // Exit 1 means the remote has commits HEAD lacks; any other failure is a git error.
        if ($ancestorExitCode > 1) {
            throw $cannotUpdate;
        }

        $branchMoved = $ancestorExitCode === 1;

        if ($branchMoved) {
            $rebase = $sandbox->run($containerName, "cd {$workspacePath} && git rebase {$remoteRef}", timeout: 120);

            if ($rebase->exitCode() !== 0) {
                $sandbox->run($containerName, "cd {$workspacePath} && git rebase --abort", timeout: 30);

                throw new ExternalBranchPushException("The branch `{$branchName}` changed while I was working, and my commits no longer apply cleanly.");
            }
        }

        $push = $sandbox->run($containerName, "cd {$workspacePath} && git push origin " . escapeshellarg("HEAD:{$branchName}"), timeout: 60);

        if ($push->exitCode() !== 0) {
            throw new ExternalBranchPushException("GitHub rejected the push to `{$branchName}`. The branch may have changed or be protected.");
        }
    }
}
