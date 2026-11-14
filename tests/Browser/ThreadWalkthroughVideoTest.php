<?php

use App\Enums\TurnState;
use App\Models\Artifact;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Str;

it('leaves a finished video alone once the recording it watched is done', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'branch' => 'yak/census']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/census']);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    ThreadTurnMessage::query()->create(['task_run_id' => $run->id, 'uuid' => (string) Str::uuid(), 'kind' => 'prompt', 'state' => 'started', 'text' => 'Record', 'intent' => 'record_walkthrough']);
    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}")->assertVisible('[data-testid="walkthrough-recording"]');

    $run->update(['turn_state' => TurnState::Completed]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'video', 'role' => 'cut', 'filename' => 'cut.mp4', 'disk_path' => "{$task->id}/cut.mp4", 'size_bytes' => 1]);

    $page->wait(6)->assertVisible('[data-testid="walkthrough-video"] video')->assertMissing('[data-testid="walkthrough-recording"]');
    $page->script("document.querySelector('[data-testid=\"walkthrough-video\"] video').dataset.mark = document.querySelector('[data-testid=\"walkthrough-video\"] video').src");

    $page->wait(9)->assertScript("(() => { const video = document.querySelector('[data-testid=\"walkthrough-video\"] video'); return video.dataset.mark === video.src; })()", true)
        ->assertNoJavaScriptErrors();
});
