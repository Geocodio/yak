<?php

use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/web', 'is_active' => true]);
    $this->task = YakTask::factory()->create([
        'repo' => 'unknown',
        'status' => TaskStatus::AwaitingClarification,
        'started_by_user_id' => $this->user->id,
        'clarification_options' => ['acme/web'],
    ]);
    $this->thread = app(ThreadFactory::class)->forRequestWithoutRepository($this->task);
    $this->actingAs($this->user);
});

it('sends hasRepository false and a waiting-for-a-repository progress row', function () {
    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.hasRepository', false)
        ->where('progress.steps.0', fn ($step) => $step['key'] === 'received' && $step['state'] === 'done')
        ->where('progress.steps.1', fn ($step) => $step['key'] === 'waiting_for_repository' && $step['label'] === 'Waiting for a repository' && $step['state'] === 'current')
        ->where('progress.steps.2.key', 'working')
        ->has('progress.steps', 6));
});

it('sends hasRepository true and the usual progress once a repository is attached', function () {
    app(ThreadFactory::class)->attachRepository($this->thread, 'acme/web');
    $this->task->update(['repo' => 'acme/web', 'status' => TaskStatus::Running]);

    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.hasRepository', true)
        ->where('progress.steps.1.key', 'picked_up'));
});

it('drops the repository question once the request is cancelled', function () {
    $this->task->update(['status' => TaskStatus::Cancelled]);

    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page->where('repositoryQuestion', null));
});
