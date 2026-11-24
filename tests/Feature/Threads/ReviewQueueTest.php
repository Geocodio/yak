<?php

use App\Enums\ReviewState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\ReviewQueue;
use Carbon\CarbonImmutable;

function reviewThread(array $repository = [], ?Thread $thread = null): Thread
{
    $thread ??= Thread::factory()->create();
    ThreadRepository::factory()->create([
        'thread_id' => $thread->id,
        'pull_request_state' => 'open',
        'pull_request_number' => 7,
        'pull_request_url' => 'https://github.com/acme/widgets/pull/7',
        'pull_request_opened_at' => CarbonImmutable::parse('2026-11-20 10:00'),
        'pull_request_head_sha' => 'head1',
        ...$repository,
    ]);

    return $thread->fresh();
}

function reviewViewer(string $login = 'mathias'): User
{
    return User::factory()->create(['github_login' => $login]);
}

function reviewOf(string $state, ?string $commitSha = 'head1'): array
{
    return ['state' => $state, 'commitSha' => $commitSha];
}

test('a thread without an open PR is not in the queue', function () {
    $thread = reviewThread(['pull_request_state' => 'merged']);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null))->toBeNull();
});

test('not reviewed when the viewer has neither driven a turn since the PR opened nor reviewed', function () {
    $thread = reviewThread();

    $summary = ReviewQueue::summarize($thread, reviewViewer(), false, CarbonImmutable::parse('2026-11-19 10:00'));

    expect($summary['state'])->toBe(ReviewState::NotReviewed->value)
        ->and($summary['pullRequests'])->toBe([['repo' => 'acme/widgets', 'number' => 7, 'url' => 'https://github.com/acme/widgets/pull/7', 'isDraft' => false]]);
});

test('back to you after the viewer drives a turn that finished', function () {
    $summary = ReviewQueue::summarize(reviewThread(), reviewViewer(), false, CarbonImmutable::parse('2026-11-21 10:00'));

    expect($summary['state'])->toBe(ReviewState::BackToYou->value);
});

test('back to you after a GitHub comment review', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('commented')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['state'])->toBe(ReviewState::BackToYou->value);
});

test('with yak while a turn is running', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('commented')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), true, null)['state'])->toBe(ReviewState::WithYak->value);
});

test('approved when the viewer approved the head commit', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('approved')]]);

    $summary = ReviewQueue::summarize($thread, reviewViewer(), true, null);

    expect($summary['state'])->toBe(ReviewState::Approved->value)
        ->and($summary['hasNewCommitsSinceApproval'])->toBeFalse();
});

test('an approval counts as current when the head commit is unknown', function () {
    $thread = reviewThread(['pull_request_head_sha' => null, 'pull_request_reviews' => ['mathias' => reviewOf('approved', 'old')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['state'])->toBe(ReviewState::Approved->value);
});

test('back to you with new commits when the approval is on an older commit', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('approved', 'old')]]);

    $summary = ReviewQueue::summarize($thread, reviewViewer(), false, null);

    expect($summary['state'])->toBe(ReviewState::BackToYou->value)
        ->and($summary['hasNewCommitsSinceApproval'])->toBeTrue();
});

test('matches the login case-insensitively', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('approved')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer('Mathias'), false, null)['state'])->toBe(ReviewState::Approved->value);
});

test('only open PRs count', function () {
    $thread = reviewThread(['pull_request_state' => 'merged', 'pull_request_number' => 3, 'repo' => 'acme/old']);
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('approved')]], $thread);

    $summary = ReviewQueue::summarize($thread, reviewViewer(), false, null);

    expect($summary['state'])->toBe(ReviewState::Approved->value)
        ->and($summary['pullRequests'])->toHaveCount(1);
});

test('a missing opened time still counts GitHub reviews', function () {
    $thread = reviewThread(['pull_request_opened_at' => null, 'pull_request_reviews' => ['mathias' => reviewOf('changes_requested')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['state'])->toBe(ReviewState::BackToYou->value);
});

test('approved needs every open PR approved', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('approved')]]);
    $thread = reviewThread(['pull_request_number' => 8, 'repo' => 'acme/other'], $thread);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['state'])->not->toBe(ReviewState::Approved->value);
});

test('sub-thread PRs belong to the root thread', function () {
    $root = Thread::factory()->create();
    reviewThread([], Thread::factory()->create(['parent_thread_id' => $root->id]));

    expect(ReviewQueue::summarize($root->fresh(), reviewViewer(), false, null)['pullRequests'])->toHaveCount(1);
});

test('blocked label prefers conflicts over CI', function () {
    $thread = reviewThread(['pull_request_mergeable_state' => 'dirty', 'ci_status' => 'failing']);
    $ciOnly = reviewThread(['ci_status' => 'failing']);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['blockedLabel'])->toBe('Conflicts')
        ->and(ReviewQueue::summarize($ciOnly, reviewViewer(), false, null)['blockedLabel'])->toBe('CI failing');
});

test('flags drafts', function () {
    $thread = reviewThread(['pull_request_is_draft' => true]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['pullRequests'][0]['isDraft'])->toBeTrue();
});

test('counts other approvals', function () {
    $thread = reviewThread(['pull_request_reviews' => ['mathias' => reviewOf('commented'), 'sam' => reviewOf('approved'), 'kim' => reviewOf('changes_requested')]]);

    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['otherApprovals'])->toBe(1);
});

test('ci is passing only when every open PR has passing CI', function () {
    $thread = reviewThread(['ci_status' => 'passing']);
    expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['isCiPassing'])->toBeTrue();

    foreach (['running', 'failing', null] as $status) {
        $thread = reviewThread(['ci_status' => $status]);
        expect(ReviewQueue::summarize($thread, reviewViewer(), false, null)['isCiPassing'])->toBeFalse();
    }

    $thread = reviewThread(['ci_status' => 'passing']);
    reviewThread(['ci_status' => 'running', 'repo' => 'acme/other'], $thread);
    expect(ReviewQueue::summarize($thread->fresh(), reviewViewer(), false, null)['isCiPassing'])->toBeFalse();
});
