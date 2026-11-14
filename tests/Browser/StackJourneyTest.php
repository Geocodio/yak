<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\TurnState;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Threads\ThreadEventPublisher;

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
        ->click('[data-testid="stack-limit-increase"]')
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
        $child = Thread::factory()->child($parent)->create();
        $task = YakTask::factory()->create(['thread_id' => $child->id]);
        TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
    }

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="thread-header-subthreads"]', '3 sub-threads')
        ->assertSeeIn('[data-testid="stack-limit-summary"]', '3 of 3 awake');
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

it('orders the stack panel and collapses the messages', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create(['name' => 'scout']);
    StackThreadMessage::factory()->count(4)->create(['root_thread_id' => $parent->id, 'from_thread_id' => $child->id, 'to_thread_id' => $parent->id, 'kind' => StackThreadMessageKind::Result]);

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="stack-children-header"]', '1 of 12')
        ->assertSeeIn('[data-testid="stack-child-' . $child->public_id . '"] [data-testid="state-pill"]', 'Waiting for a slot')
        ->assertScript('document.querySelectorAll(\'[data-testid^="stack-message-"]\').length', 3)
        ->assertScript('(() => { const top = (testid) => document.querySelector(`[data-testid="${testid}"]`).getBoundingClientRect().top; return top("stack-children-header") < top("stack-limit"); })()', true)
        ->click('[data-testid="stack-messages-show-all"]')
        ->assertScript('document.querySelectorAll(\'[data-testid^="stack-message-"]\').length', 4);
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
        ->assertSeeIn('[data-testid="child-card-' . $queued->public_id . '"]', 'Starts when another sub-thread finishes')
        ->assertSeeIn('[data-testid="child-card-' . $asking->public_id . '"]', 'Asked you')
        ->assertSeeIn('[data-testid="child-card-' . $asking->public_id . '"]', 'Asks you: Which table should hold it?')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', 'Merged into this branch')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', 'Merge conflict resolved')
        ->assertSeeIn('[data-testid="child-card-' . $merged->public_id . '"]', '3 files');
});
