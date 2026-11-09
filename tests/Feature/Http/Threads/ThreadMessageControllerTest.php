<?php

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;

it('lists the messages of a thread with the documented shape', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'prompt' => 'Do it', 'prompt_uuid' => 'p1', 'command_states' => ['p1' => 'started'], 'prompt_read_after_sequence' => 0]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('threads.messages.index', $thread))
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonStructure(['messages' => [['id', 'uuid', 'text', 'authorName', 'model', 'state', 'readAfterSequence', 'sentAt', 'readAt']]])
        ->assertJsonPath('messages.0.state', 'read')
        ->assertJsonPath('messages.0.readAfterSequence', 0);
});

it('refuses messages of a thread to a guest', function () {
    $this->get(route('threads.messages.index', Thread::factory()->create()))->assertRedirect(route('login'));
});
