<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadRepository;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('it lists changed files and shows a diff', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->withFiles(3)->create();
    $file = $diff->files()->first();

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->assertSeeIn('[data-testid="changes-summary"]', '3 files changed')
        ->click('[data-testid="changes-file-' . $file->path . '"] button')
        ->assertSeeIn('[data-testid="changes-diff-viewer"]', 'newest');
});

test('it shows an empty state', function () {
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->assertPresent('[data-testid="changes-empty"]');
});

test('it shows the working note while a turn runs', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(1)->create();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->assertPresent('[data-testid="changes-working-note"]');
});

test('it shows the changed file count on the Changes tab', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(2)->create();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="thread-panel-count-changes"]', '2');
});

test('the header shows diff totals and the pull request, and a sub-thread shows its brief', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['title' => 'Ship the importer']);
    $child = Thread::factory()->child($parent)->create(['title' => 'Build the client', 'brief' => 'Build the API client.', 'branch' => 'yak/imp/client']);
    ThreadDiff::factory()->create(['thread_id' => $child->id, 'files_count' => 1, 'additions' => 7, 'deletions' => 2]);
    ThreadRepository::factory()->create(['thread_id' => $child->id, 'pull_request_url' => 'https://github.com/acme/app/pull/9', 'pull_request_number' => 9]);

    visit(route('threads.show', $child))
        ->assertSeeIn('[data-testid="thread-header-diff"]', '+7')
        ->assertSeeIn('[data-testid="thread-header-pr"]', 'PR #9')
        ->assertSeeIn('[data-testid="thread-breadcrumb-parent"]', 'Ship the importer')
        ->assertSeeIn('[data-testid="child-brief"]', 'Brief from Ship the importer')
        ->assertSeeIn('[data-testid="child-brief"]', 'Works on yak/imp/client');
});
