<?php

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

it('maps each follow-up task to the element id of its first message for old turn fragments', function () {
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);
    $followUp = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $followUp->id, 'thread_id' => $thread->id]);
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);
    ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'text' => 'later']);
    $other = YakTask::factory()->create(['parent_task_id' => $root->id]);
    ThreadTurnMessage::factory()->create(['task_run_id' => TaskRun::factory()->create(['yak_task_id' => $other->id])->id]);

    $this->withoutVite()->actingAs(User::factory()->create())->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->where('turnAnchors', [$followUp->public_id => 'message-' . $message->uuid]));
});

it('maps the root task to the first message of the thread', function () {
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $thread->id]);
    $first = ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    $this->withoutVite()->actingAs(User::factory()->create())->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page->where('turnAnchors', [$root->public_id => 'message-' . $first->uuid]));
});
