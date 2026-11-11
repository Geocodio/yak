<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
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
