<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

test('the empty state shows when there are no threads', function () {
    $this->actingAs(User::factory()->create());

    visit('/tasks')
        ->assertVisible('[data-testid="thread-list-empty"]')
        ->assertMissing('[data-testid="rail-threads-badge"]')
        ->assertNoJavaScriptErrors();
});

test('a finished unseen thread sits in the needs you group', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Fix the cache']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'turn_state' => TurnState::Completed,
        'finished_at' => now()->subHour(),
    ]);
    $this->actingAs($user);

    visit('/tasks')
        ->assertVisible('[data-testid="thread-group-needs-you"]')
        ->assertVisible('[data-testid="thread-row-' . $thread->id . '"]')
        ->assertVisible('[data-testid="rail-threads-badge"]')
        ->assertNoJavaScriptErrors();
});

test('the everyone filter shows threads from teammates', function () {
    $user = User::factory()->create();
    $other = Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id, 'title' => 'Teammate work']);
    $this->actingAs($user);

    visit('/tasks')
        ->assertMissing('[data-testid="thread-row-' . $other->id . '"]')
        ->click('[data-testid="thread-filter"] button[aria-pressed="false"]')
        ->assertVisible('[data-testid="thread-row-' . $other->id . '"]')
        ->assertNoJavaScriptErrors();
});

test('the owner sees a private thread with its badge', function () {
    $user = User::factory()->create();
    $private = Thread::factory()->private($user)->create(['created_by_user_id' => $user->id, 'title' => 'Secret work']);
    $this->actingAs($user);

    visit('/tasks')
        ->assertVisible('[data-testid="thread-row-' . $private->id . '"]')
        ->assertSee('Private')
        ->assertNoJavaScriptErrors();
});

test('children are nested under their parent and can be collapsed', function () {
    $user = User::factory()->create();
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Parent work']);
    Thread::factory()->child($parent)->create(['created_by_user_id' => $user->id, 'title' => 'Child work']);
    $this->actingAs($user);

    visit('/tasks')
        ->assertVisible('[data-testid="thread-children-' . $parent->id . '"]')
        ->click('[data-testid="thread-row-' . $parent->id . '"] button[aria-label="Collapse sub-threads"]')
        ->assertMissing('[data-testid="thread-children-' . $parent->id . '"]')
        ->assertVisible('[data-testid="thread-dots-' . $parent->id . '"]')
        ->assertNoJavaScriptErrors();
});
