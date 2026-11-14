<?php

use App\Enums\RunnerState;
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

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->assertSeeIn('[data-testid="changes-summary"]', '3 files changed')
        ->click('[data-testid="changes-file-' . $file->path . '"] button')
        ->assertScript('document.querySelector(\'[data-testid="changes-diff-viewer"]\').textContent.includes("newest")', true);
});

test('it shows an empty state', function () {
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->click('[data-testid="files-mode-changed"]')
        ->assertPresent('[data-testid="changes-empty"]');
});

test('the empty state says Claude is working while the runner is busy', function () {
    $thread = Thread::factory()->create(['runner_state' => RunnerState::Busy]);

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->click('[data-testid="files-mode-changed"]')
        ->assertSeeIn('[data-testid="changes-working"]', 'Claude is working')
        ->assertSeeIn('[data-testid="changes-empty"]', 'when this turn ends');
});

test('it shows the working note while a turn runs', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(1)->create();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->assertPresent('[data-testid="changes-working-note"]');
});

test('it shows the changed file count on the Changes tab', function () {
    $thread = Thread::factory()->create();
    ThreadDiff::factory()->for($thread)->withFiles(2)->create();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="thread-dock-count-files"]', '2');
});

test('the header shows diff totals and the pull request, and a sub-thread shows its brief', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['title' => 'Ship the importer']);
    $child = Thread::factory()->child($parent)->create(['title' => 'Build the client', 'brief' => 'Build the API client.', 'branch' => 'yak/imp/client']);
    ThreadDiff::factory()->create(['thread_id' => $child->id, 'files_count' => 1, 'additions' => 7, 'deletions' => 2]);
    ThreadRepository::factory()->create(['thread_id' => $child->id, 'pull_request_url' => 'https://github.com/acme/app/pull/9', 'pull_request_number' => 9]);

    visit(route('threads.show', $child))
        ->assertSeeIn('[data-testid="thread-header-diff"]', '+7')
        ->assertSeeIn('[data-testid="thread-header-pr"]', '#9')
        ->assertSeeIn('[data-testid="thread-breadcrumb-parent"]', 'Ship the importer')
        ->assertSeeIn('[data-testid="child-brief"]', 'Brief from Ship the importer')
        ->assertSeeIn('[data-testid="child-brief"]', 'Works on yak/imp/client');
});

test('the file list leads with a status tile and shows viewed progress and the filter hint', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->withFiles(2)->create();
    $file = $diff->files()->first();

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->assertPresent('[data-testid="changes-file-' . $file->path . '"] [data-testid="changes-status-tile"]')
        ->assertSeeIn('[data-testid="changes-viewed-progress"]', '0 of 2 viewed')
        ->assertPresent('[data-testid="changes-filter-hint"]')
        ->assertPresent('button[aria-label="Keyboard shortcuts"]');

    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: '/' }))");
    $page->assertScript('document.activeElement.getAttribute("aria-label")', 'Filter files');
});

test('every changed file is a card, and a renamed banner shows only when the old path is known', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => 2]);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/new-name.php', 'old_path' => 'src/old-name.php', 'status' => 'renamed']);
    ThreadDiffFile::factory()->create(['thread_diff_id' => $diff->id, 'path' => 'src/other.php', 'old_path' => null, 'status' => 'renamed']);

    $page = visit(route('threads.show', $thread));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->assertCount('[data-testid="changes-file-card"]', 2)
        ->assertSeeIn('[data-testid="changes-pane"]', 'src/new-name.php')
        ->assertSeeIn('[data-testid="changes-pane"]', 'src/other.php')
        ->assertCount('[data-testid="changes-renamed-banner"]', 1)
        ->assertSeeIn('[data-testid="changes-renamed-banner"]', 'Renamed from src/old-name.php')
        ->assertDontSee('Renamed from null');
});

test('the stack select is labelled and counts each sub-thread files, leaving the count off when unknown', function () {
    $parent = Thread::factory()->create();
    $counted = Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the API']);
    Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the client']);
    ThreadDiff::factory()->for($counted)->withFiles(3)->create();

    $page = visit(route('threads.show', $parent));

    $page->click('[data-testid="thread-dock-toggle-files"]')
        ->click('[data-testid="files-mode-changed"]')
        ->assertSee('Showing')
        ->click('[data-testid="changes-child-switch"]')
        ->assertSee('This thread')
        ->assertSee('scout (3 files)')
        ->assertDontSee('builder (')
        ->assertSee('builder');
});

test('the Overview rail has no tabs and lists the Terminal row', function () {
    $thread = Thread::factory()->create();

    $page = visit(route('threads.show', $thread));

    $page->assertVisible('[data-testid="thread-sidebar"]')
        ->assertMissing('[data-testid="thread-sidebar"] [role="tablist"]')
        ->assertSeeIn('[data-testid="environment-terminal"]', 'yak ssh ' . $thread->public_id)
        ->assertSeeIn('[data-testid="environment-terminal"]', 'Set up SSH')
        ->assertNoJavaScriptErrors();
});
