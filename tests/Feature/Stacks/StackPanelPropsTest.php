<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('gives a child page its parent for the banner', function () {
    $parent = Thread::factory()->create(['title' => 'Root work', 'name' => 'root-work']);
    $child = Thread::factory()->child($parent)->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$child->id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.parentThreadId', $parent->id)
        ->where('stackParent.id', $parent->id)
        ->where('stackParent.title', 'Root work'));
});

it('gives a root page no parent', function () {
    $root = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$root->id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.parentThreadId', null)
        ->where('stackParent', null));
});

it('stack show includes limit awake and waiting counts', function () {
    $root = Thread::factory()->create();
    $working = Thread::factory()->child($root)->create();
    $workingTask = YakTask::factory()->create(['thread_id' => $working->id]);
    TaskRun::factory()->create(['thread_id' => $working->id, 'yak_task_id' => $workingTask->id, 'turn_state' => TurnState::Running]);
    Thread::factory()->child($root)->create(['queued_for_slot_at' => now()]);

    $this->actingAs(User::factory()->create())->getJson(route('threads.stack.show', $root))
        ->assertJsonPath('limit.awake', 1)
        ->assertJsonPath('limit.waiting', 1);
});

it('stack show reports zero awake and waiting for a stack with no children', function () {
    $root = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->getJson(route('threads.stack.show', $root))
        ->assertJsonPath('limit.awake', 0)
        ->assertJsonPath('limit.waiting', 0)
        ->assertJsonPath('children', []);
});

it('stack show reports the changed file count of a child, or null without a diff', function () {
    $root = Thread::factory()->create();
    $withDiff = Thread::factory()->child($root)->create();
    ThreadDiff::factory()->create(['thread_id' => $withDiff->id, 'files_count' => 3]);
    $withoutDiff = Thread::factory()->child($root)->create();

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.stack.show', $root));

    expect(collect($response->json('children'))->pluck('filesChanged', 'id')->all())->toBe([$withDiff->id => 3, $withoutDiff->id => null]);
});
