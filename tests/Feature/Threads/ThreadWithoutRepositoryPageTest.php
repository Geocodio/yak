<?php

use App\Enums\SteeringMode;
use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadTurns;
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

it('lists the other active repositories for Another repository, and keeps the card when every offered one went inactive', function () {
    Repository::factory()->create(['slug' => 'acme/api', 'is_active' => true]);
    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('repositoryQuestion.options', ['acme/web'])
        ->where('repositoryQuestion.otherOptions', ['acme/api']));

    Repository::where('slug', 'acme/web')->update(['is_active' => false]);

    $this->get("/threads/{$this->thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('card', 'repository_question')
        ->where('repositoryQuestion.options', [])
        ->where('repositoryQuestion.otherOptions', ['acme/api']));
});

it('starts work in an active repository that was not offered, through the web route', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/api', 'is_active' => true]);

    $this->post("/threads/{$this->thread->public_id}/repository-answer", ['repo' => 'acme/api'])->assertSessionHasNoErrors();

    expect($this->thread->fresh()->repo)->toBe('acme/api')->and($this->task->fresh()->status)->toBe(TaskStatus::Pending);
});

it('refuses an inactive or unknown repository on the web route', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/old', 'is_active' => false]);

    foreach (['acme/old', 'acme/missing'] as $repo) {
        $this->post("/threads/{$this->thread->public_id}/repository-answer", ['repo' => $repo])->assertSessionHas('error');
    }

    expect($this->thread->fresh()->hasRepository())->toBeFalse();
});

it('does not let a viewer who cannot drive the thread pick a repository', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/api', 'is_active' => true]);
    $this->thread->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id])->save();
    $viewer = User::factory()->create();
    $this->thread->shares()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->post("/threads/{$this->thread->public_id}/repository-answer", ['repo' => 'acme/api']);

    expect($this->thread->fresh()->hasRepository())->toBeFalse();
});

it('keeps a composer message on a thread without a repository and sends it with the first prompt', function () {
    $this->postJson("/threads/{$this->thread->public_id}/messages", ['message' => 'Also fix the timezone.'])->assertOk();

    $queued = PendingSteeringMessage::query()->where('text', 'Also fix the timezone.')->first();

    expect($queued)->not->toBeNull()
        ->and($queued->mode)->toBe(SteeringMode::Queue)
        ->and(PendingSteeringMessage::takeableFor($this->task)->pluck('id')->all())->toContain($queued->id)
        ->and($this->thread->runs()->count())->toBe(0);
});

it('puts a queued composer message into the first prompt once the repository is picked', function () {
    require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['repo' => 'unknown'])->save();
    $task->forceFill(['repo' => 'unknown'])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn');

    $this->postJson("/threads/{$thread->public_id}/messages", ['message' => 'Also fix the timezone.'])->assertOk();
    $thread->forceFill(['repo' => 'acme/web'])->save();
    $task->forceFill(['repo' => 'acme/web'])->save();
    $run = app(ThreadTurns::class)->start($task->fresh(), 'Fix the login bug', $this->user, null);

    expect($run->prompt)->toContain('Also fix the timezone.')->toContain('Fix the login bug')
        ->and(PendingSteeringMessage::query()->where('text', 'Also fix the timezone.')->doesntExist() || PendingSteeringMessage::takeableFor($task)->isEmpty())->toBeTrue();
});
