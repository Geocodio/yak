<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function failingAgentResult(): AgentRunResult
{
    return AgentRunResult::failure('Agent blew up', '{}');
}

beforeEach(function () {
    Queue::fake();
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);
    Repository::factory()->create(['slug' => 'fail-repo', 'path' => '/home/yak/repos/fail-repo']);
});

test('RunYakJob tells the source when the run fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'linear']);

    (new RunYakJob($task))->handle($fake);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->message === 'Agent blew up');
});

test('RunYakJob tells the source when an exception fails the run', function () {
    $fake = (new FakeAgentRunner)->queueException(new RuntimeException('Agent finished with uncommitted changes'));
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'linear']);

    (new RunYakJob($task))->handle($fake);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && str_contains($job->message, 'uncommitted changes'));
});

test('an auth failure notifies exactly once', function () {
    $fake = (new FakeAgentRunner)->queueException(new ClaudeAuthException('Claude CLI authentication error'));
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'slack']);

    (new RunYakJob($task))->handle($fake);

    Queue::assertPushed(
        SendNotificationJob::class,
        fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error,
    );
    expect(Queue::pushed(SendNotificationJob::class)->filter(
        fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error,
    ))->toHaveCount(1);
});

test('a system task failure does not notify', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'system']);

    (new RunYakJob($task))->handle($fake);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('a task that is already terminal is not notified again', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'linear']);

    $job = new RunYakJob($task);
    YakTask::whereKey($task->id)->update(['status' => TaskStatus::Cancelled]);

    $handleError = new ReflectionMethod($job, 'handleError');
    $handleError->invoke($job, 'late failure');

    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled);
    Queue::assertNotPushed(SendNotificationJob::class);
});

test('RetryYakJob tells the source when the retry fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->retrying()->create(['repo' => 'fail-repo', 'source' => 'linear', 'branch_name' => 'yak/x']);

    (new RetryYakJob($task, 'ci output'))->handle($fake);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('ResearchYakJob tells the source when research fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'linear', 'mode' => 'research']);

    (new ResearchYakJob($task))->handle($fake);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});
