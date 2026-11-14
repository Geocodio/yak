<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RepositoryConfigParser;
use App\Services\Threads\ThreadTurns;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->thread = Thread::factory()->create();
    YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->user = User::factory()->create();
});

it('sends the full preview schema to Claude and the short message to the timeline', function (string $intent, string $needle) {
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once()->withArgs(function ($task, string $prompt, $user, $model, $kind = null, $shownText = null) use ($needle) {
        return $shownText === 'Please set up the preview'
            && str_contains($prompt, '`health_probe_path`')
            && str_contains($prompt, '`cold_start_timeout_seconds`')
            && str_contains($prompt, 'REQUIRED')
            && str_contains($prompt, 'Definition of done')
            && str_contains($prompt, $needle);
    });

    $this->actingAs($this->user)
        ->post(route('threads.messages.store', $this->thread), ['message' => 'Please set up the preview', 'intent' => $intent])
        ->assertSessionHas('success');
})->with([
    'add' => ['add_preview_manifest', 'has no `.yak/preview.yml`'],
    'fix' => ['fix_preview', 'did not start'],
]);

it('refuses a preview request while a turn runs', function () {
    $run = TaskRun::factory()->create(['yak_task_id' => $this->thread->tasks()->first()->id, 'thread_id' => $this->thread->id, 'turn_state' => TurnState::Running]);
    $this->mock(ThreadTurns::class)->shouldNotReceive('start', 'steer');

    $this->actingAs($this->user)
        ->post(route('threads.messages.store', $this->thread), ['message' => 'x', 'intent' => 'add_preview_manifest'])
        ->assertSessionHas('error');
});

it('lists every key the preview.yml parser knows in the setup prompt and system prompt', function () {
    $prompt = YakPromptBuilder::previewSetupPrompt('missing');

    foreach (RepositoryConfigParser::keyTree('preview.yml') as $key => $children) {
        expect($prompt)->toContain("`{$key}`");
    }

    expect(YakPromptBuilder::systemPrompt(YakTask::factory()->create()))->toContain('.yak/preview.yml')->toContain('health_probe_path');
});
