<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
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

test('the file list leads with a status tile and shows viewed progress and the filter hint', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->withFiles(2)->create();
    $file = $diff->files()->first();

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->assertPresent('[data-testid="changes-file-' . $file->path . '"] [data-testid="changes-status-tile"]')
        ->assertSeeIn('[data-testid="changes-viewed-progress"]', '0 of 2 viewed')
        ->assertPresent('[data-testid="changes-filter-hint"]')
        ->assertPresent('button[aria-label="Keyboard shortcuts"]');

    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: '/' }))");
    $page->assertScript('document.activeElement.getAttribute("aria-label")', 'Filter files');
});

test('an opened file shows the stepper and a renamed banner only when the old path is known', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 2]);
    $renamed = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/new-name.php', 'old_path' => 'src/old-name.php', 'status' => 'renamed']);
    $unknown = ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/other.php', 'old_path' => null, 'status' => 'renamed']);

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->click('[data-testid="changes-file-' . $renamed->path . '"] button')
        ->assertSeeIn('[data-testid="changes-file-header"]', 'src/new-name.php')
        ->assertSeeIn('[data-testid="changes-renamed-banner"]', 'Renamed from src/old-name.php')
        ->assertSee('1/2')
        ->click('button[aria-label="Next file"]')
        ->assertSeeIn('[data-testid="changes-file-header"]', 'src/other.php')
        ->assertMissing('[data-testid="changes-renamed-banner"]')
        ->assertDontSee('Renamed from null');
});

test('the stack select is labelled and counts each sub-thread files, leaving the count off when unknown', function () {
    $parent = Thread::factory()->create();
    $counted = Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the API']);
    Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the client']);
    ThreadDiff::factory()->for($counted)->withFiles(3)->create();

    $page = visit(route('threads.show', $parent));

    $page->click('[data-testid="thread-panel-tab-changes"]')
        ->assertSee('Showing')
        ->click('[data-testid="changes-child-switch"]')
        ->assertSee('This thread')
        ->assertSee('scout (3 files)')
        ->assertDontSee('builder (')
        ->assertSee('builder');
});

test('the Environment tab is first and lists the Terminal row', function () {
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread));

    expect($page->script('document.querySelector(\'[data-testid="thread-panel-tab-environment"]\').getAttribute("aria-selected")'))->toBe('true');
    expect($page->script('[...document.querySelectorAll(\'[role="tablist"][aria-label="Thread panels"] [role="tab"]\')].map(t => t.dataset.testid).join(",")'))
        ->toStartWith('thread-panel-tab-environment');
    $page->assertSeeIn('[data-testid="environment-terminal"]', 'yak ssh ' . $thread->id)
        ->assertSeeIn('[data-testid="environment-terminal"]', 'Set up SSH')
        ->assertNoJavaScriptErrors();
});
