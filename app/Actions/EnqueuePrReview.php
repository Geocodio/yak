<?php

namespace App\Actions;

use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskJobResolver;

class EnqueuePrReview
{
    /**
     * @param  array<string, mixed>  $prPayload
     */
    public function __invoke(Repository $repository, array $prPayload, string $reviewScope, ?string $incrementalBaseSha = null): ?YakTask
    {
        if ($repository->approvalLevel() >= 2) {
            $reviewScope = 'full';
            $incrementalBaseSha = null;
        }

        $prUrl = (string) $prPayload['html_url'];
        $headSha = (string) $prPayload['head']['sha'];

        if ($this->isDuplicate($prUrl, $headSha)) {
            return null;
        }

        $title = (string) ($prPayload['title'] ?? '');
        $description = "Review PR #{$prPayload['number']}" . ($title !== '' ? ": {$title}" : '');

        return YakTask::create([
            'mode' => TaskMode::Review,
            'source' => 'github',
            'description' => $description,
            'repo' => $repository->slug,
            'external_id' => $prUrl,
            'pr_url' => $prUrl,
            'branch_name' => (string) $prPayload['head']['ref'],
            'context' => json_encode([
                'pr_number' => (int) $prPayload['number'],
                'head_sha' => $headSha,
                'head_ref' => (string) $prPayload['head']['ref'],
                'base_sha' => (string) $prPayload['base']['sha'],
                'base_ref' => (string) $prPayload['base']['ref'],
                'author' => (string) $prPayload['user']['login'],
                'title' => (string) ($prPayload['title'] ?? ''),
                'body' => (string) ($prPayload['body'] ?? ''),
                'review_scope' => $reviewScope,
                'incremental_base_sha' => $incrementalBaseSha,
            ]),
            'status' => 'pending',
        ]);
    }

    /**
     * Why a pull request must not be reviewed, or null when it may be.
     *
     * @param  array<string, mixed>  $pr
     */
    public function skipReason(Repository $repository, array $pr): ?string
    {
        if (! $repository->is_active) {
            return 'repo not registered or inactive';
        }

        if (! $repository->settings()->reviewEnabled()) {
            return 'pr review disabled on repo';
        }

        if ((bool) ($pr['draft'] ?? false)) {
            return 'draft PR';
        }

        if (! config('yak.pr_review.self_review_enabled') && (string) ($pr['user']['login'] ?? '') === app(AppService::class)->appBotLogin()) {
            return 'yak-authored PR';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $prPayload
     */
    public function dispatch(Repository $repository, array $prPayload, string $reviewScope, ?string $incrementalBaseSha = null): ?YakTask
    {
        $task = $this($repository, $prPayload, $reviewScope, $incrementalBaseSha);

        if ($task !== null && ! $this->isAwaitingSetup($repository)) {
            TaskJobResolver::dispatch($task);
        }

        return $task;
    }

    /**
     * Dispatches the reviews held back while the repository's setup ran.
     * A Pending review that is already queued gets a second copy, which the
     * atomic task claim discards.
     */
    public function releaseHeld(Repository $repository): void
    {
        YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('repo', $repository->slug)
            ->where('status', TaskStatus::Pending)
            ->each(fn (YakTask $task) => TaskJobResolver::dispatch($task));
    }

    /**
     * A review queued while setup is building the sandbox has nothing to run
     * against yet, so it waits for setup instead of being refused.
     */
    private function isAwaitingSetup(Repository $repository): bool
    {
        return empty($repository->sandbox_snapshot)
            && in_array($repository->setup_status, ['pending', 'running'], true);
    }

    private function isDuplicate(string $prUrl, string $headSha): bool
    {
        $candidates = YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('external_id', $prUrl)
            ->whereIn('status', ['pending', 'running'])
            ->get(['context']);

        foreach ($candidates as $task) {
            $ctx = json_decode((string) $task->context, true);
            if (is_array($ctx) && ($ctx['head_sha'] ?? null) === $headSha) {
                return true;
            }
        }

        return false;
    }
}
