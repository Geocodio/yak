<?php

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
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
        ->click('[data-testid="stack-stop-confirm"]');

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
