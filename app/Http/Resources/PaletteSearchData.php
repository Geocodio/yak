<?php

namespace App\Http\Resources;

use App\Models\Repository;
use App\Models\Thread;

/**
 * The command palette's search results. Mirrored by resources/js/types/paletteSearch.ts.
 */
final class PaletteSearchData
{
    /**
     * @param  array{threads: list<Thread>, previewUrls: array<int, ?string>, repositories: list<Repository>, pullRequests: list<array{number: int, title: string, repo: string, url: string, threadId: ?string, reviewUrl: ?string}>}  $results
     * @return array{
     *     threads: list<array{id: string, title: string, repo: string, branch: ?string, status: 'open'|'closed', previewUrl: ?string, pullRequestUrl: ?string, lastActivityAt: ?string}>,
     *     repositories: list<array{slug: string, name: string, url: string, githubUrl: ?string, isActive: bool}>,
     *     pullRequests: list<array{number: int, title: string, repo: string, url: string, threadId: ?string, reviewUrl: ?string}>,
     * }
     */
    public static function from(array $results): array
    {
        return [
            'threads' => array_map(fn (Thread $thread): array => [
                'id' => $thread->public_id,
                'title' => $thread->title,
                'repo' => $thread->repo,
                'branch' => $thread->branch,
                'status' => $thread->status->value,
                'previewUrl' => $results['previewUrls'][$thread->id] ?? null,
                'pullRequestUrl' => $thread->repositories->first()?->pull_request_url,
                'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
            ], $results['threads']),
            'repositories' => array_map(fn (Repository $repository): array => [
                'slug' => $repository->slug,
                'name' => $repository->name,
                'url' => route('repos.edit', $repository),
                'githubUrl' => $repository->githubUrl(),
                'isActive' => $repository->is_active,
            ], $results['repositories']),
            'pullRequests' => $results['pullRequests'],
        ];
    }
}
