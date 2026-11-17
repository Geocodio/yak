<?php

use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Http\Resources\ThreadNoticeData;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadStarter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->user->id]);
    Queue::fake();
});

function startOn(Repository $repository)
{
    return app(ThreadStarter::class)->start(test()->user, $repository->slug, TaskMode::Fix, 'Fix the login bug', null, null);
}

it('creates a thread on a pending repository without starting its first turn', function (string $status) {
    $repository = Repository::factory()->pendingSetup()->create(['setup_status' => $status]);

    $thread = startOn($repository);

    expect($thread->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
    expect($thread->tasks()->first()->status)->toBe(TaskStatus::Pending);
    Queue::assertNotPushed(RunYakJob::class);
    expect(ThreadNoticeData::for($thread)['kind'])->toBe('waiting_for_repository');
})->with(['pending', 'running']);

it('starts the waiting first turn when setup completes', function () {
    $repository = Repository::factory()->pendingSetup()->create();
    $thread = startOn($repository);
    $other = startOn($repository);

    $repository->update(['setup_status' => 'running']);
    Queue::assertNotPushed(RunYakJob::class);

    $repository->update(['setup_status' => 'ready', 'sandbox_snapshot' => 'yak-tpl/ready']);

    expect($thread->fresh()->blocked_reason)->toBeNull()->and($other->fresh()->blocked_reason)->toBeNull();
    Queue::assertPushed(RunYakJob::class, 2);
    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->is($thread->tasks()->first()));
});

it('marks waiting threads when setup fails and starts them once a re-run succeeds', function () {
    $repository = Repository::factory()->pendingSetup()->create();
    $thread = startOn($repository);

    $repository->update(['setup_status' => 'failed']);

    expect($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::RepositorySetupFailed);
    $notice = ThreadNoticeData::for($thread->fresh());
    expect($notice['kind'])->toBe('repository_setup_failed')->and($notice['repositoryUrl'])->toBe(route('repos.edit', $repository->slug));
    Queue::assertNotPushed(RunYakJob::class);

    $repository->update(['setup_status' => 'pending']);
    expect($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);

    $repository->update(['setup_status' => 'ready']);
    expect($thread->fresh()->blocked_reason)->toBeNull();
    Queue::assertPushed(RunYakJob::class, 1);
});

it('does not queue a thread on a repository whose setup failed', function () {
    $repository = Repository::factory()->pendingSetup()->create(['setup_status' => 'failed']);

    expect(fn () => startOn($repository))->toThrow(ValidationException::class);
    expect(YakTask::query()->count())->toBe(0);
});

it('starts at once on a ready repository', function () {
    $thread = startOn(Repository::factory()->create());

    expect($thread->blocked_reason)->toBeNull();
    Queue::assertPushed(RunYakJob::class, 1);
});

it('refuses messages to a thread that has not started', function () {
    $thread = startOn(Repository::factory()->pendingSetup()->create());

    $this->actingAs($this->user)->postJson(route('threads.messages.store', $thread), ['message' => 'Hello'])->assertStatus(422);
});

it('reports the setup step from the setup task log', function () {
    $setupTask = YakTask::factory()->create(['mode' => TaskMode::Setup]);
    $repository = Repository::factory()->pendingSetup()->create(['setup_status' => 'running', 'setup_task_id' => $setupTask->id]);
    TaskLog::query()->create(['yak_task_id' => $setupTask->id, 'level' => 'info', 'message' => 'Cloning https://github.com/acme/x.git in sandbox', 'created_at' => now()]);

    $this->actingAs($this->user)->getJson(route('threads.repository-setup', ['repo' => $repository->slug]))
        ->assertOk()->assertJson(['status' => 'running', 'step' => 'Cloning the repository']);
});

it('tells the starter once when setup fails and starts the task when a re-run succeeds', function () {
    $repository = Repository::factory()->pendingSetup()->create();
    $thread = startOn($repository);
    $task = $thread->tasks()->first();
    $task->update(['source' => 'slack']);
    $setupTask = YakTask::factory()->create(['repo' => $repository->slug, 'mode' => TaskMode::Setup, 'status' => TaskStatus::Failed, 'error_log' => 'composer exploded']);
    $repository->update(['setup_task_id' => $setupTask->id]);

    $repository->update(['setup_status' => 'failed']);
    $repository->update(['setup_status' => 'failed']);

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->stop?->kind === StopKind::YakStopped
        && $job->stop->detail === 'composer exploded');
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);

    $repository->update(['setup_status' => 'running']);
    $repository->update(['setup_status' => 'ready', 'sandbox_snapshot' => 'yak-tpl/ready']);

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->is($task));
});

it('releases a waiting task with the job its kind needs', function () {
    $repository = Repository::factory()->create(['setup_status' => 'running', 'sandbox_snapshot' => null]);
    $waiting = fn (array $attributes): YakTask => YakTask::factory()->pending()->create([
        'repo' => $repository->slug,
        'thread_id' => Thread::factory()->create(['repo' => $repository->slug, 'blocked_reason' => ThreadBlockReason::WaitingForRepository])->id,
        ...$attributes,
    ]);
    $parent = YakTask::factory()->create(['repo' => $repository->slug, 'status' => TaskStatus::Success]);
    $research = $waiting(['mode' => TaskMode::Research]);
    $followUp = $waiting(['mode' => TaskMode::Fix, 'parent_task_id' => $parent->id]);
    $researchFollowUp = $waiting(['mode' => TaskMode::Research, 'parent_task_id' => $parent->id]);

    $repository->update(['setup_status' => 'ready', 'sandbox_snapshot' => 'yak-tpl/ready']);

    Queue::assertPushed(ResearchYakJob::class, fn ($job) => $job->task->is($research));
    Queue::assertPushed(RunFollowUpJob::class, fn ($job) => $job->task->is($followUp));
    Queue::assertPushed(ResearchFollowUpJob::class, fn ($job) => $job->task->is($researchFollowUp));
    Queue::assertNotPushed(RunYakJob::class);
});
