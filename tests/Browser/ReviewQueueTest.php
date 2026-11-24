<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;

function reviewQueueThread(User $user, string $title, array $repository = []): Thread
{
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => $title]);
    ThreadRepository::factory()->create([
        'thread_id' => $thread->id,
        'repo' => 'acme/widgets',
        'pull_request_state' => 'open',
        'pull_request_number' => 7,
        'pull_request_url' => 'https://github.com/acme/widgets/pull/7',
        'pull_request_opened_at' => now()->subDay(),
        'pull_request_head_sha' => 'head1',
        ...$repository,
    ]);

    return $thread;
}

test('the review tab groups the viewer\'s pull requests by review state', function () {
    $user = User::factory()->withGitHub(4242, 'mathias')->create();
    $backToYou = reviewQueueThread($user, 'Back to you thread', ['pull_request_reviews' => ['mathias' => ['state' => 'commented', 'commitSha' => 'head1']]]);
    $notReviewed = reviewQueueThread($user, 'Not reviewed thread', ['pull_request_mergeable_state' => 'dirty']);
    $withYak = reviewQueueThread($user, 'With Yak thread');
    $approved = reviewQueueThread($user, 'Approved thread', ['pull_request_reviews' => ['mathias' => ['state' => 'approved', 'commitSha' => 'head1']]]);
    TaskRun::factory()->create([
        'yak_task_id' => YakTask::factory()->create(['thread_id' => $withYak->id])->id,
        'thread_id' => $withYak->id,
        'turn_state' => TurnState::Running,
    ]);
    $this->actingAs($user);

    visit('/threads/new')
        ->click('[data-testid="thread-filter-review"]')
        ->assertSeeIn('[data-testid="review-group-back-to-you"]', 'Back to you thread')
        ->assertSeeIn('[data-testid="review-group-not-reviewed"]', 'Not reviewed thread')
        ->assertSeeIn('[data-testid="review-group-not-reviewed"]', 'Conflicts')
        ->assertSeeIn('[data-testid="review-group-with-yak"]', 'With Yak thread')
        ->assertSeeIn('[data-testid="review-group-approved"]', 'Approved by you')
        ->assertSeeIn('[data-testid="review-group-approved"] [data-testid="thread-group-count"]', '1')
        ->assertSeeIn('[data-testid="review-group-back-to-you"] [data-testid="thread-row-pr"]', '#7')
        ->assertSeeIn('[data-testid="review-group-back-to-you"] [data-testid="thread-row-pr"]', 'Ready')
        ->assertDontSeeIn('[data-testid="review-group-approved"]', 'Approved thread')
        ->click('[data-testid="review-group-approved"] button[aria-expanded]')
        ->assertSeeIn('[data-testid="review-group-approved"]', 'Approved thread')
        ->assertNoJavaScriptErrors();
});

test('the review tab asks a viewer without a GitHub account to link one', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => $user->id]);
    $this->actingAs($user);

    visit('/threads/new')
        ->click('[data-testid="thread-filter-review"]')
        ->assertVisible('[data-testid="review-link-github"]')
        ->assertAttribute('[data-testid="review-link-github"]', 'href', route('profile.edit', absolute: false))
        ->assertNoJavaScriptErrors();
});
