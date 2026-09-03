<?php

use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('dispatch stamps dispatched_at and refuses a job class it does not allow', function () {
    Queue::fake();

    $repository = Repository::factory()->create(['slug' => 'ajd-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);

    $task->refresh();
    expect($task->dispatched_at)->not->toBeNull();

    Queue::assertPushed(RunYakJob::class, fn ($job) => $job->task->id === $task->id);
});

test('dispatch rejects a job class outside the allowed four', function () {
    $task = YakTask::factory()->pending()->create();

    app(AgentJobDispatcher::class)->dispatch($task, RetryYakJob::class);
})->throws(InvalidArgumentException::class);

test('dispatch captures the queue job uuid when the job actually reaches the queue', function () {
    config(['queue.default' => 'database']);

    $repository = Repository::factory()->create(['slug' => 'ajd-uuid-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);

    $task->refresh();
    expect($task->dispatched_at)->not->toBeNull()
        ->and($task->queue_job_uuid)->not->toBeNull();

    $stillQueued = DB::table('jobs')
        ->where('payload', 'like', '%"uuid":"' . $task->queue_job_uuid . '"%')
        ->exists();

    expect($stillQueued)->toBeTrue();
});

dataset('claiming job classes', [
    RunYakJob::class,
    ResearchYakJob::class,
    RunYakReviewJob::class,
    SetupYakJob::class,
]);

test('dispatch accepts every claiming job class', function (string $jobClass) {
    Queue::fake();

    $attributes = ['slug' => 'ajd-' . str(str($jobClass)->afterLast('\\'))->kebab()];
    if ($jobClass === RunYakReviewJob::class) {
        $attributes['pr_review_enabled'] = true;
    }
    $repository = Repository::factory()->create($attributes);

    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    app(AgentJobDispatcher::class)->dispatch($task, $jobClass);

    Queue::assertPushed($jobClass, fn ($job) => $job->task->id === $task->id);
})->with('claiming job classes');
