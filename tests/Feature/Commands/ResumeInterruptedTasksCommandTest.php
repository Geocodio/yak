<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::forget(PausesDuringDrain::CACHE_KEY);
});

test('requeues a marked task and clears the marker, error_log and completed_at', function () {
    Queue::fake();

    $repository = Repository::factory()->create(['slug' => 'resume-repo']);
    $task = YakTask::factory()->failed()->create([
        'repo' => $repository->slug,
        'attempts' => 1,
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => now(),
        'error_log' => 'Deploy interrupted the task after 300s with no activity.',
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending)
        ->and($task->interrupted_by_deploy_at)->toBeNull()
        ->and($task->error_log)->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($task->deploy_resume_count)->toBe(1);

    Queue::assertPushed(RunYakJob::class, fn ($job) => $job->task->id === $task->id);
    Queue::assertPushed(
        SendNotificationJob::class,
        fn ($job) => $job->task->id === $task->id && $job->type === NotificationType::Retry,
    );
});

test('dispatches the original job class, not RunYakJob by default', function () {
    Queue::fake();

    $repository = Repository::factory()->create(['slug' => 'resume-research-repo']);
    $task = YakTask::factory()->failed()->create([
        'repo' => $repository->slug,
        'mode' => 'fix',
        'attempts' => 1,
        'claimed_job_class' => ResearchYakJob::class,
        'interrupted_by_deploy_at' => now(),
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    Queue::assertPushed(ResearchYakJob::class, fn ($job) => $job->task->id === $task->id);
    Queue::assertNotPushed(RunYakJob::class);
});

test('does not consume tasks.attempts and preserves the full CI-retry budget', function () {
    Queue::fake();

    $repository = Repository::factory()->create(['slug' => 'resume-attempts-repo']);
    $task = YakTask::factory()->failed()->create([
        'repo' => $repository->slug,
        'attempts' => 1,
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => now(),
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    // Resume decrements by one before re-dispatching so that ClaimsTask's
    // unconditional claim increment nets back to the pre-interruption
    // value.
    expect($task->attempts)->toBe(0);

    // Simulate the claim a re-dispatched RunYakJob would perform.
    YakTask::where('id', $task->id)->update([
        'status' => TaskStatus::Running->value,
        'attempts' => DB::raw('attempts + 1'),
    ]);

    $task->refresh();
    expect($task->attempts)->toBe(1)
        ->and($task->attempts)->toBeLessThan((int) config('yak.max_attempts'));
});

test('does not resume a follow-up task', function () {
    Queue::fake();

    $task = YakTask::factory()->failed()->create([
        'claimed_job_class' => RunFollowUpJob::class,
        'interrupted_by_deploy_at' => now(),
        'error_log' => 'Deploy interrupted the task after 300s with no activity. It needs a manual retry once the deploy is done.',
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->interrupted_by_deploy_at)->toBeNull()
        ->and($task->error_log)->toContain('manual retry');

    Queue::assertNothingPushed();
});

test('does not resume a clarification reply task', function () {
    Queue::fake();

    $task = YakTask::factory()->failed()->create([
        'claimed_job_class' => ClarificationReplyJob::class,
        'interrupted_by_deploy_at' => now(),
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->interrupted_by_deploy_at)->toBeNull();

    Queue::assertNothingPushed();
});

test('does not resume a retry task', function () {
    Queue::fake();

    $task = YakTask::factory()->failed()->create([
        // A Retrying task's claimed_job_class still reflects its original
        // RunYakJob claim from before it ever reached AwaitingCi — RetryYakJob
        // never touches the column — so status at interrupt time is what
        // actually excludes it. Drain leaves claimed_job_class untouched.
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => null,
    ])->fresh();

    // RetryYakJob never claims via ClaimsTask, so simulate what drain would
    // have recorded for a Retrying straggler directly: no eligible class is
    // ever promised for it.
    $task->update([
        'interrupted_by_deploy_at' => now(),
        'claimed_job_class' => RetryYakJob::class,
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->interrupted_by_deploy_at)->toBeNull();

    Queue::assertNothingPushed();
});

test('leaves a task Failed once it is over the deploy_resume_count bound', function () {
    Queue::fake();

    $task = YakTask::factory()->failed()->create([
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => now(),
        'deploy_resume_count' => 3,
    ]);

    $this->artisan('yak:resume-interrupted-tasks', ['--max-resumes' => 3])->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->interrupted_by_deploy_at)->toBeNull()
        ->and($task->deploy_resume_count)->toBe(3)
        ->and($task->error_log)->toContain('manual retry');

    Queue::assertNothingPushed();
});

test('is a no-op while the drain flag is set', function () {
    Queue::fake();
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(5));

    $task = YakTask::factory()->failed()->create([
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => now(),
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->interrupted_by_deploy_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

test('leaves tasks without the marker untouched', function () {
    Queue::fake();

    $task = YakTask::factory()->failed()->create([
        'claimed_job_class' => RunYakJob::class,
        'interrupted_by_deploy_at' => null,
    ]);

    $this->artisan('yak:resume-interrupted-tasks')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);

    Queue::assertNothingPushed();
});
