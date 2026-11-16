<?php

use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\ThreadPullRequestsPanel;

function panelFor(Thread $thread): array
{
    return app(ThreadPullRequestsPanel::class)->build($thread, null);
}

it('names the other repositories in the blocked banner', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->for($thread)->withPullRequest(1)->create(['repo' => 'acme/api', 'ci_status' => 'passing', 'merge_position' => 1]);
    ThreadRepository::factory()->for($thread)->changes()->withPullRequest(2)->create(['repo' => 'acme/web', 'ci_status' => 'failing', 'merge_position' => 2]);

    expect(panelFor($thread)['banner']['body'])->toBe(
        'Fix it before merging in order. The other pull request does not depend on this check, but merging half of a change leaves the web and the api out of step.'
    );
});

it('falls back to the repositories when the blocked pair is not two', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->for($thread)->withPullRequest(1)->create(['repo' => 'acme/api', 'ci_status' => 'failing', 'merge_position' => 1]);

    expect(panelFor($thread)['banner']['body'])->toContain('leaves the repositories out of step.');
});

it('lists what to expect from the repository roles and counts scratch files on context repos', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/api']);
    ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web']);
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);
    ThreadDiffRepository::factory()->for(ThreadDiff::factory()->create(['thread_id' => $thread->id]), 'diff')->create(['repo' => 'acme/docs', 'files_count' => 3]);

    $panel = panelFor($thread);

    expect($panel['state'])->toBe('none')
        ->and($panel['expectations'])->toBe(['acme/api opens first', 'acme/web waits for it', 'acme/docs gets none, it is context'])
        ->and(collect($panel['withoutPullRequest'])->firstWhere('repo', 'acme/docs')['note'])
        ->toBe('No pull request. Claude left 3 scratch files there, and they go away with the sandbox.');
});

it('omits the scratch sentence when a context repo has no files', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);

    expect(panelFor($thread)['withoutPullRequest'][0]['note'])->toBe('No pull request.');
});
