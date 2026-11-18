<?php

use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

test('child task URL redirects to its thread', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);
    $child = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id]);

    $this->get(route('tasks.show', $child))
        ->assertStatus(301)
        ->assertRedirect(route('threads.show', $thread));
});

test('root task redirects to its thread', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->get(route('tasks.show', $root))->assertStatus(301)->assertRedirect(route('threads.show', $thread));
});
