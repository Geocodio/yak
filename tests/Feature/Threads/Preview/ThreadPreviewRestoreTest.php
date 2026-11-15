<?php

use App\Enums\RestoreStepStatus;
use App\Enums\ThreadSandboxState;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner, ['sandbox_state' => ThreadSandboxState::Destroyed]);
});

it('refuses to restore a sandbox that is still there', function () {
    $this->thread->update(['sandbox_state' => ThreadSandboxState::Running]);

    $this->actingAs($this->owner)->postJson(route('threads.preview.restore', $this->thread))
        ->assertStatus(422)
        ->assertExactJson(['message' => 'The sandbox is still there, so there is nothing to restore.']);

    Queue::assertNothingPushed();
});

it('keeps answering 202 while a restore runs', function () {
    ThreadRestoreStep::factory()->create(['thread_id' => $this->thread->id, 'status' => RestoreStepStatus::Running, 'started_at' => now()->subMinute()]);

    $this->actingAs($this->owner)->postJson(route('threads.preview.restore', $this->thread))
        ->assertStatus(202)->assertExactJson(['ok' => true]);

    Queue::assertNothingPushed();
});

it('uses one service for both routes', function () {
    $this->actingAs($this->owner)->postJson(route('threads.preview.restore', $this->thread))->assertStatus(202);
    $this->postJson(appThreadPath($this->thread, '/preview/restore'), [], phoneHeadersFor($this->owner))->assertStatus(202);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});
