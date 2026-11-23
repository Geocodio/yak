<?php

namespace App\Http\Resources;

use App\Models\Repository;
use App\Models\Thread;

/**
 * The app's search results: the command palette's matches as one flat list. `type` is `thread`, `repository` or
 * `pull_request`; treat an unknown type as a row to show by title. A thread row carries the `threadId` to open;
 * the others carry the web `path` (a pull request without a review has none) and, for a pull request, its GitHub `url`.
 */
final class AppSearchData
{
    /**
     * @param  array{threads: list<Thread>, previewUrls: array<int, ?string>, repositories: list<Repository>, pullRequests: list<array{number: int, title: string, repo: string, url: string, threadId: ?string, reviewUrl: ?string}>}  $results
     * @return array{results: list<array{type: string, id: string, title: string, subtitle: string, threadId: ?string, path: ?string, url: ?string}>}
     */
    public static function from(array $results): array
    {
        $items = [];

        foreach ($results['threads'] as $thread) {
            $items[] = [
                'type' => 'thread',
                'id' => $thread->public_id,
                'title' => $thread->title,
                'subtitle' => $thread->repo . ($thread->branch === null ? '' : ' / ' . $thread->branch),
                'threadId' => $thread->public_id,
                'path' => route('threads.show', $thread, false),
                'url' => $thread->repositories->first()?->pull_request_url,
            ];
        }

        foreach ($results['repositories'] as $repository) {
            $items[] = [
                'type' => 'repository',
                'id' => $repository->slug,
                'title' => $repository->name,
                'subtitle' => $repository->slug,
                'threadId' => null,
                'path' => route('repos.edit', $repository, false),
                'url' => $repository->githubUrl(),
            ];
        }

        foreach ($results['pullRequests'] as $pullRequest) {
            $items[] = [
                'type' => 'pull_request',
                'id' => $pullRequest['url'],
                'title' => $pullRequest['title'],
                'subtitle' => $pullRequest['repo'] . ' #' . $pullRequest['number'],
                'threadId' => $pullRequest['threadId'],
                'path' => $pullRequest['reviewUrl'] === null ? null : parse_url($pullRequest['reviewUrl'], PHP_URL_PATH),
                'url' => $pullRequest['url'],
            ];
        }

        return ['results' => $items];
    }
}
