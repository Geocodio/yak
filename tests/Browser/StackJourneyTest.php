<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\TurnState;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildCardPublisher;

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
        ->assertPresent('[data-testid="child-card-' . $done->id . '"]')
        ->assertSeeIn('[data-testid="child-card-' . $done->id . '"]', 'The API pages by cursor.')
        ->assertPresent('[data-testid="child-take-over"]')
        ->click('[data-testid="thread-panel-tab-stack"]')
        ->assertPresent('[data-testid="stack-panel"]')
        ->assertPresent('[data-testid="stack-child-' . $failed->id . '"]')
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

it('shows awake over total on the Stack tab', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();
    foreach (range(1, 3) as $index) {
        $child = Thread::factory()->child($parent)->create();
        $task = YakTask::factory()->create(['thread_id' => $child->id]);
        TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);
    }

    visit(route('threads.show', $parent))
        ->assertSeeIn('[data-testid="thread-panel-count-stack"]', '3/3');
});

it('shows an empty awake meter for a stack with no sub-threads', function () {
    $this->actingAs(User::factory()->create());
    $parent = Thread::factory()->create();

    visit(route('threads.show', $parent))
        ->click('[data-testid="thread-panel-tab-stack"]')
        ->assertSeeIn('[data-testid="stack-limit-summary"]', '0 of 3 awake')
        ->assertScript('document.querySelectorAll(\'[data-testid="stack-awake-meter"] span\').length', 3)
        ->assertScript('document.querySelectorAll(\'[data-testid="stack-awake-meter"] span[data-filled="true"]\').length', 0);
});
