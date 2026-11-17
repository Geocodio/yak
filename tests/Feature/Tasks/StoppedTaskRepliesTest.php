<?php

use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\Tasks\StoppedTaskReplies;
use Illuminate\Support\Facades\Queue;

function stoppedTask(array $attributes = []): YakTask
{
    $repository = Repository::factory()->create(['slug' => 'Geocodio/geocodio', 'is_active' => true]);

    return YakTask::factory()->create(['repo' => $repository->slug, 'status' => TaskStatus::Failed, 'pr_url' => null, ...$attributes]);
}

it('retries a failed task with the reply as a note', function (): void {
    Queue::fake();
    $task = stoppedTask();

    $reply = app(StoppedTaskReplies::class)->handle($task, 'use the helper', 'linear', 'Sam');

    expect($reply)->toBe('Trying again with your note.')
        ->and($task->fresh()->description)->toContain('use the helper');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('retries an expired clarification task', function (): void {
    Queue::fake();
    $task = stoppedTask(['status' => TaskStatus::Expired]);

    expect(app(StoppedTaskReplies::class)->handle($task, 'go on', 'linear'))->toBe('Trying again with your note.');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('does not dispatch a second time when a reply arrives while retrying', function (): void {
    Queue::fake();
    $task = stoppedTask();

    app(StoppedTaskReplies::class)->handle($task, 'first', 'linear');
    $reply = app(StoppedTaskReplies::class)->handle($task->fresh(), 'second', 'linear');

    expect($reply)->toBe('Yak is already on it. Your note is included.');
    Queue::assertPushed(RunYakJob::class, 1);
    expect(PendingSteeringMessage::where('text', 'second')->exists())->toBeTrue();
});

it('does not retry a cancelled task', function (): void {
    Queue::fake();
    $task = stoppedTask(['status' => TaskStatus::Cancelled]);

    expect(app(StoppedTaskReplies::class)->handle($task, 'again', 'linear'))->toBe('This was stopped. Delegate it to Yak again to start over.')
        ->and(app(StoppedTaskReplies::class)->handle($task, 'again', 'slack'))->toBe('This was stopped. Start a new request to try again.');
    Queue::assertNothingPushed();
});

it('reroutes an unknown-repository task when the reply names an active repository', function (): void {
    Queue::fake();
    Repository::factory()->create(['slug' => 'Geocodio/atlas', 'is_active' => true]);
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::Failed, 'pr_url' => null]);

    expect(app(StoppedTaskReplies::class)->handle($task, 'atlas', 'linear'))->toBe('Working in Geocodio/atlas now.');
    expect($task->fresh()->repo)->toBe('Geocodio/atlas');
});

it('asks which repository when the reply matches none', function (): void {
    Queue::fake();
    Repository::factory()->create(['slug' => 'Geocodio/atlas', 'is_active' => true]);
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::Failed, 'pr_url' => null]);

    expect(app(StoppedTaskReplies::class)->handle($task, 'hmm', 'linear'))->toBe('Which repository? One of: Geocodio/atlas.');
    Queue::assertNothingPushed();
    expect($task->fresh()->repo)->toBe('unknown');
});

it('says it cannot retry when the task is not retryable', function (): void {
    Queue::fake();
    $task = stoppedTask(['status' => TaskStatus::Success]);

    expect(app(StoppedTaskReplies::class)->handle($task, 'again', 'linear'))->toBe("Yak can't retry this one. Start a new request and it'll pick it up.");
});
