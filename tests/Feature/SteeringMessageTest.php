<?php

use App\Enums\TaskStatus;
use App\Jobs\FlushSteeringMessagesJob;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;

test('queueFor resolves the chain root', function () {
    $root = YakTask::factory()->create();
    $child = YakTask::factory()->create(['parent_task_id' => $root->id]);

    $msg = PendingSteeringMessage::queueFor($child, 'also do X', 'dashboard');

    expect($msg->root_task_id)->toBe($root->id);
});

test('flush composes queued messages into one follow-up and clears them', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/geocodio/geocodio/pull/1']);
    PendingSteeringMessage::queueFor($root, 'first note', 'dashboard');
    PendingSteeringMessage::queueFor($root, 'second note', 'slack');

    $this->mock(FollowUpTaskFactory::class)
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (YakTask $parent, string $instructions, string $source) => str_contains($instructions, 'first note')
            && str_contains($instructions, 'second note')
            && $source === 'steering')
        ->andReturn(YakTask::factory()->create(['parent_task_id' => $root->id]));

    (new FlushSteeringMessagesJob($root->id))->handle(app(FollowUpTaskFactory::class));

    expect(PendingSteeringMessage::where('root_task_id', $root->id)->count())->toBe(0);
});

test('flush keeps messages when the follow-up cannot be created', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success]);
    PendingSteeringMessage::queueFor($root, 'note', 'dashboard');

    $this->mock(FollowUpTaskFactory::class)
        ->shouldReceive('create')->once()->andReturn(null);

    (new FlushSteeringMessagesJob($root->id))->handle(app(FollowUpTaskFactory::class));

    expect(PendingSteeringMessage::count())->toBe(1);
});

test('transitioning to Success dispatches the flush job when steering messages are pending', function () {
    Queue::fake([FlushSteeringMessagesJob::class]);

    $root = YakTask::factory()->running()->create();
    PendingSteeringMessage::queueFor($root, 'a note', 'dashboard');

    $root->update(['status' => TaskStatus::Success]);

    Queue::assertPushed(FlushSteeringMessagesJob::class, fn (FlushSteeringMessagesJob $job) => $job->rootTaskId === $root->id);
});

test('transitioning to Success does not dispatch the flush job when nothing is pending', function () {
    Queue::fake([FlushSteeringMessagesJob::class]);

    $root = YakTask::factory()->running()->create();

    $root->update(['status' => TaskStatus::Success]);

    Queue::assertNotPushed(FlushSteeringMessagesJob::class);
});

test('a save that does not change status does not dispatch the flush job', function () {
    Queue::fake([FlushSteeringMessagesJob::class]);

    $root = YakTask::factory()->running()->create();
    PendingSteeringMessage::queueFor($root, 'a note', 'dashboard');

    $root->update(['description' => 'updated description']);

    Queue::assertNotPushed(FlushSteeringMessagesJob::class);
});
