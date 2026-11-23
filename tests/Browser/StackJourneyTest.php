<?php

use App\Enums\RunnerState;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../Helpers/BrowserHelpers.php';

/**
 * Journey 7: a thread spawns sub-threads, one result comes back, one child fails,
 * the person steps in and finally stops the stack.
 */
it('shows sub-threads as cards and in the Stack panel, and stops the stack', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['title' => 'Ship the importer', 'name' => 'importer']);
    $done = Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the API', 'result_summary' => 'The API pages by cursor.']);
    $failed = Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the client']);

    StackThreadMessage::factory()->create([
        'root_thread_id' => $parent->id,
        'from_thread_id' => $done->id,
        'to_thread_id' => $parent->id,
        'kind' => StackThreadMessageKind::Result,
        'text' => 'The API pages by cursor.',
        'delivered_at' => now(),
    ]);
    StackThreadMessage::factory()->create([
        'root_thread_id' => $parent->id,
        'from_thread_id' => $failed->id,
        'to_thread_id' => $parent->id,
        'kind' => StackThreadMessageKind::Failure,
        'text' => 'Sub-thread builder failed before finishing.',
        'delivered_at' => now(),
    ]);

    foreach ([$done, $failed] as $child) {
        app(ChildCardPublisher::class)->publish($child);
    }

    $this->actingAs($user);

    visit(route('threads.show', $parent))
        ->assertPresent('[data-testid="child-card-' . $done->public_id . '"]')
        ->assertSeeIn('[data-testid="child-card-' . $done->public_id . '"]', 'The API pages by cursor.')
        ->assertPresent('[data-testid="child-take-over"]')
        ->assertPresent('[data-testid="stack-panel"]')
        ->assertPresent('[data-testid="stack-child-' . $failed->public_id . '"]')
        ->click('[data-testid="stack-menu"]')
        ->click('[data-testid="stack-stop"]')
        ->click('[data-testid="stack-stop-confirm"]')
        ->assertMissing('[data-testid="stack-stop-confirm"]');

    expect($parent->fresh()->stack_stopped_at)->not->toBeNull();
});

it('shows the parent banner on a sub-thread page', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['title' => 'Ship the importer']);
    $child = Thread::factory()->child($parent)->create();

    $this->actingAs($user);

    visit(route('threads.show', $child))
        ->assertSeeIn('[data-testid="child-parent-banner"]', 'Ship the importer');
});

it('shows the sub-thread count in the header and the awake total in the Stack group', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    foreach (range(1, 3) as $index) {
        $child = Thread::factory()->child($parent)->create(['sandbox_state' => ThreadSandboxState::Running]);
        $task = YakTask::factory()->create(['thread_id' => $child->id]);
        TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
    }

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="thread-header-subthreads"]', '3 sub-threads')
        ->assertSeeIn('[data-testid="stack-limit-summary"]', '3 can run at the same time');
});

it('hides the Stack group and the header count while a thread has no sub-threads', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();

    visit(route('threads.show', $parent))
        ->assertPresent('[data-testid="environment-panel"]')
        ->assertMissing('[data-testid="stack-panel"]')
        ->assertMissing('[data-testid="thread-header-subthreads"]')
        ->assertNoJavaScriptErrors();
});

it('shows the Stack group first in the Overview once a sub-thread exists', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    Thread::factory()->child($parent)->create(['name' => 'scout']);

    visit(route('threads.show', $parent))
        ->assertPresent('[data-testid="stack-panel"]')
        ->assertScript('(() => { const top = (testid) => document.querySelector(`[data-testid="${testid}"]`).getBoundingClientRect().top; return top("stack-panel") < top("environment-panel"); })()', true)
        ->assertNoJavaScriptErrors();
});

it('lists a sub-thread that has not run yet as Starting and leaves the Messages box out of the sidebar', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['name' => 'scout']);
    StackThreadMessage::factory()->count(4)->create(['root_thread_id' => $parent->id, 'from_thread_id' => $child->id, 'to_thread_id' => $parent->id, 'kind' => StackThreadMessageKind::Result]);

    $page = visit(route('threads.show', $parent));
    waitUntilPresent($page, '[data-testid="stack-child-' . $child->public_id . '"]');

    $page->assertSeeIn('[data-testid="stack-children-header"]', '1 of 12')
        ->assertSeeIn('[data-testid="stack-child-' . $child->public_id . '-line"]', 'Implement · Starting')
        ->assertMissing('[data-testid="stack-messages"]')
        ->assertDontSee('Messages come from threads');
});

it('shows no limit line while every sub-thread is a research one', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['name' => 'reader', 'kind' => ThreadKind::Research, 'queued_for_slot_at' => now()]);

    $page = visit(route('threads.show', $parent));
    waitUntilPresent($page, '[data-testid="stack-child-' . $child->public_id . '"]');

    $page->assertSeeIn('[data-testid="stack-child-' . $child->public_id . '-line"]', 'Research · Starting')
        ->assertMissing('[data-testid="stack-limit-summary"]')
        ->assertMissing('[data-testid="stack-child-' . $child->public_id . '-reason"]');
});

it('explains why a sub-thread waits and raises the limit by one from its button', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['child_awake_limit' => 1]);
    Thread::factory()->child($parent)->create(['sandbox_state' => ThreadSandboxState::Running]);
    $waiting = Thread::factory()->child($parent)->create(['name' => 'settings', 'queued_for_slot_at' => now()]);

    $page = visit(route('threads.show', $parent));
    waitUntilPresent($page, '[data-testid="stack-child-' . $waiting->public_id . '-start-now"]');

    $page->assertSeeIn('[data-testid="stack-child-' . $waiting->public_id . '-reason"]', 'Waiting for a free spot. 1 sub-thread is already running, so this one starts when it finishes.')
        ->assertSeeIn('[data-testid="stack-child-' . $waiting->public_id . '-start-now"]', 'Start now and allow 2 at a time')
        ->assertSeeIn('[data-testid="stack-limit-summary"]', '1 can run at the same time')
        ->click('[data-testid="stack-child-' . $waiting->public_id . '-start-now"]');
    waitUntilPresent($page, '[data-testid="stack-limit-summary"]');

    expect($parent->fresh()->child_awake_limit)->toBe(2);
});

it('edits the limit inline with Save and Cancel', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['child_awake_limit' => 3]);
    Thread::factory()->child($parent)->create();

    $page = visit(route('threads.show', $parent));
    waitUntilPresent($page, '[data-testid="stack-limit-change"]');

    $page->click('[data-testid="stack-limit-change"]')
        ->assertSeeIn('[data-testid="stack-limit-editor"]', 'Running at the same time')
        ->assertSeeIn('[data-testid="stack-limit-editor"]', 'Max ' . config('yak.threads.person_awake_cap'))
        ->click('[data-testid="stack-limit-increase"]')
        ->click('[data-testid="stack-limit-cancel"]');
    waitUntilPresent($page, '[data-testid="stack-limit-editor"]', false);

    expect($parent->fresh()->child_awake_limit)->toBe(3);

    $page->click('[data-testid="stack-limit-change"]')
        ->click('[data-testid="stack-limit-increase"]')
        ->click('[data-testid="stack-limit-save"]');
    waitUntilPresent($page, '[data-testid="stack-limit-editor"]', false);

    $page->assertSeeIn('[data-testid="stack-limit-summary"]', '4 can run at the same time');
    expect($parent->fresh()->child_awake_limit)->toBe(4);
});

it('shows a sub-thread result as a collapsed row that opens, and a failure already open', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['runner_state' => RunnerState::Idle]);
    $child = Thread::factory()->child($parent)->create(['title' => 'Read the website README', 'kind' => ThreadKind::Research]);
    $broken = Thread::factory()->child($parent)->create(['title' => 'Build the client']);
    $events = app(ThreadEventPublisher::class);
    $events->append($parent->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'text', 'text' => 'I started a research sub-thread.']]]);
    $events->append($parent->id, 'child.result', ['id' => 1, 'childThreadId' => $child->public_id, 'title' => 'Read the website README', 'kind' => 'research', 'state' => 'done', 'reason' => null, 'text' => 'The README', 'html' => '<p>The first <code>README.md</code> lines</p>']);
    $events->append($parent->id, 'child.result', ['id' => 2, 'childThreadId' => $broken->public_id, 'title' => 'Build the client', 'kind' => 'implement', 'state' => 'failed', 'reason' => 'Sub-thread builder failed before finishing.', 'text' => 'Sub-thread builder failed before finishing.', 'html' => '<p>Sub-thread builder failed before finishing.</p>']);
    $events->append($parent->id, 'entry', ['role' => 'assistant', 'messageId' => 'm2', 'blocks' => [['type' => 'text', 'text' => 'The research sub-thread has reported back.']]]);

    $page = visit(route('threads.show', $parent));
    waitUntilPresent($page, '[data-testid="child-result-1"]');

    $page->assertSeeIn('[data-testid="child-result-1"]', 'Result from Read the website README')
        ->assertSeeIn('[data-testid="child-result-1"]', 'Open sub-thread')
        ->assertMissing('[data-testid="child-result-1"] [data-testid="child-result-body"]')
        ->assertAttribute('[data-testid="child-result-1"] [data-testid="child-result-toggle"]', 'aria-label', 'Show the result')
        ->click('[data-testid="child-result-1"] [data-testid="child-result-toggle"]');
    waitUntilPresent($page, '[data-testid="child-result-1"] [data-testid="child-result-body"]');

    $page->assertSeeIn('[data-testid="child-result-1"] [data-testid="child-result-body"]', 'README.md')
        ->assertPresent('[data-testid="child-result-2"] [data-testid="child-result-body"]')
        ->assertSeeIn('[data-testid="child-result-2"]', 'Failed: Sub-thread builder failed before finishing.')
        ->assertMissing('[data-testid="stack-messages"]')
        ->assertNoJavaScriptErrors();
});

it('renders every sub-thread past the cap of 12 without clamping', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    Thread::factory()->count(13)->child($parent)->create();

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="stack-children-header"]', '13 of 12')
        ->assertScript('document.querySelectorAll(\'[data-testid^="stack-child-"][data-state]\').length', 13);
});

it('opens the Add a sub-thread dialog with the approved copy', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create(['title' => 'Ship the importer']);

    visit(route('threads.show', $parent))
        ->click('[data-testid="composer-plus-menu"]')
        ->click('[data-testid="composer-add-sub-thread"]')
        ->assertSeeIn('[data-testid="stack-add-dialog"]', 'Add a sub-thread')
        ->assertSeeIn('[data-testid="stack-add-dialog"]', 'Sub-thread of Ship the importer')
        ->assertSeeIn('[data-testid="stack-add-kind-implement"]', 'Implement')
        ->assertMissing('[data-testid="stack-add-notice"]')
        ->assertScript('document.querySelector(\'[data-testid="stack-add-name"]\').placeholder', 'reverb-events')
        ->assertSeeIn('[data-testid="stack-add-submit"]', 'Start sub-thread');
});

it('shows queued, asking and merged sub-threads with their new card layout', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    $queued = Thread::factory()->child($parent)->create(['name' => 'queuer']);
    $asking = Thread::factory()->child($parent)->create(['name' => 'asker']);
    $merged = Thread::factory()->child($parent)->create(['name' => 'merger']);

    $card = fn (Thread $child, string $state, array $extra = []): array => [
        'childThreadId' => $child->public_id, 'name' => $child->name, 'kind' => 'implement', 'model' => 'sonnet', 'state' => $state,
        'step' => null, 'resultSummary' => null, 'branch' => null, 'messagesPaused' => false,
        'mergedAt' => null, 'question' => null, 'filesChanged' => null, 'additions' => null, 'deletions' => null,
        'conflictResolved' => false, 'decision' => null, ...$extra,
    ];

    $events = app(ThreadEventPublisher::class);
    $events->append($parent->id, 'child', $card($queued, 'queued'));
    $events->append($parent->id, 'child', $card($asking, 'waiting', ['question' => 'Which table should hold it?']));
    $events->append($parent->id, 'child', $card($merged, 'done', ['mergedAt' => now()->toIso8601String(), 'filesChanged' => 3, 'additions' => 12, 'deletions' => 4, 'conflictResolved' => true, 'decision' => 'Kept the cursor paging.']));

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="child-card-' . $queued->public_id . '"]', 'Waiting for a free spot')
        ->assertSeeIn('[data-testid="child-card-' . $asking->public_id . '"]', 'Asked you')
        ->assertSeeIn('[data-testid="child-card-' . $asking->public_id . '"]', 'Asks you: Which table should hold it?')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', 'Merged into this branch')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', 'Merge conflict resolved')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', '3 files');
});

it('explains sub-threads and opens the Agents tab from the sidebar and the header chip', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the API']);

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="stack-definition"]', 'A separate thread with its own branch and PR. Open one to talk to it directly.')
        ->click('[data-testid="stack-view-agents"]')
        ->assertVisible('[data-testid="agents-panel"]')
        ->assertSeeIn('[data-testid="agents-subthreads"]', 'Scout the API')
        ->assertNoJavaScriptErrors();

    visit(route('threads.show', $parent))
        ->click('[data-testid="thread-header-subthreads"]')
        ->assertVisible('[data-testid="agents-panel"]')
        ->assertSeeIn('[data-testid="thread-header-subthreads"]', '1 sub-thread');
});

it('shows a sub-thread in the same state on its card, in the sidebar and in the Agents tab', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the API']);

    // The stored rows still say queued; only the card event knows the child is working.
    app(ThreadEventPublisher::class)->append($parent->id, 'child', [
        'childThreadId' => $child->public_id, 'name' => 'scout', 'kind' => 'research', 'model' => 'sonnet', 'state' => 'working',
        'step' => null, 'resultSummary' => null, 'branch' => null, 'messagesPaused' => false,
        'mergedAt' => null, 'question' => null, 'filesChanged' => null, 'additions' => null, 'deletions' => null,
        'conflictResolved' => false, 'decision' => null,
    ]);

    visit(route('threads.show', $parent))
        ->assertAttribute('[data-testid="child-card-' . $child->public_id . '"]', 'data-state', 'working')
        ->assertAttribute('[data-testid="stack-child-' . $child->public_id . '"]', 'data-state', 'working')
        ->click('[data-testid="stack-view-agents"]')
        ->assertAttribute('[data-testid="agents-subthread-row"]', 'data-state', 'working')
        ->assertNoJavaScriptErrors();
});
