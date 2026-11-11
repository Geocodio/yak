<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;

/**
 * Journey 16, pull request half: two pull requests in merge order, a failing
 * check with its excerpt, a reorder and a merged card.
 */
it('shows the pull requests of a multi-repo thread and reorders them', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api']);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web']);
    $thread = Thread::factory()->create(['title' => 'Move invoices to cursor pagination']);
    $api = ThreadRepository::factory()->for($thread)->withPullRequest(412)->create(['repo' => 'acme/api', 'pull_request_title' => 'Paginate invoices with a cursor', 'ci_status' => 'passing', 'merge_position' => 1]);
    $web = ThreadRepository::factory()->for($thread)->changes()->withPullRequest(188)->create([
        'repo' => 'acme/web',
        'pull_request_title' => 'Load invoices with a cursor',
        'ci_status' => 'failing',
        'merge_position' => 2,
        'merge_reason' => 'This web app calls the new API.',
        'ci_checks' => [
            ['name' => 'tests', 'state' => 'failing', 'durationSeconds' => 160, 'excerpt' => 'Expected a Load more button'],
            ['name' => 'lint', 'state' => 'passing', 'durationSeconds' => 28, 'excerpt' => null],
        ],
    ]);
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);

    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertPresent('[data-testid="conversation-pr-chip-acme/api"]')
        ->click('[data-testid="thread-panel-tab-pull-requests"]')
        ->assertPresent('[data-testid="pull-requests-panel"]')
        ->assertPresent('[data-testid="pull-request-card-acme/api"]')
        ->assertPresent('[data-testid="pull-request-card-acme/web"]')
        ->assertSeeIn('[data-testid="pull-requests-banner"]', 'Blocked: CI is failing in acme/web')
        ->assertSeeIn('[data-testid="ci-excerpt-acme/web"]', 'Expected a Load more button')
        ->assertSeeIn('[data-testid="merge-after-acme/web"]', 'Merge after acme/api#412')
        ->click('[data-testid="merge-order-up-acme/web"]')
        ->assertPresent('[data-testid="merge-after-acme/api"]')
        ->assertSeeIn('[data-testid="merge-after-acme/api"]', 'Merge after acme/web#188');

    expect($web->refresh()->merge_position)->toBe(1)->and($api->refresh()->merge_position)->toBe(2);

    $web->forceFill(['pull_request_state' => 'merged'])->save();

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-panel-tab-pull-requests"]')
        ->assertAttribute('[data-testid="pull-request-card-acme/web"]', 'data-state', 'merged');
});
