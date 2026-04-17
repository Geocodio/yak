<?php

namespace App\Actions;

use App\Enums\TaskMode;
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
     * @param  array<string, mixed>  $prPayload
     */
    public function dispatch(Repository $repository, array $prPayload, string $reviewScope, ?string $incrementalBaseSha = null): ?YakTask
    {
        $task = $this($repository, $prPayload, $reviewScope, $incrementalBaseSha);

        if ($task !== null) {
            TaskJobResolver::dispatch($task);
        }

        return $task;
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
