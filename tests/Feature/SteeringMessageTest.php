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

test('queueFor records the reviewer login when given', function () {
    $root = YakTask::factory()->create();

    $msg = PendingSteeringMessage::queueFor($root, 'off by one', 'github_review', reviewerLogin: 'alice');

    expect($msg->reviewer_login)->toBe('alice');
});

test('flush renders a github_review message as its own paragraph, not a bullet', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/geocodio/geocodio/pull/1']);
    PendingSteeringMessage::queueFor($root, 'plain note', 'dashboard');
    PendingSteeringMessage::queueFor(
        $root,
        "> Quoted summary\n\nInline comments:\n```\nsome hunk\n```",
        'github_review',
        reviewerLogin: 'alice',
    );

    $this->mock(FollowUpTaskFactory::class)
        ->shouldReceive('create')
        ->once()
        ->withArgs(function (YakTask $parent, string $instructions, string $source) {
            return str_contains($instructions, "- plain note\n")
                && str_contains($instructions, "\n> Quoted summary\n\nInline comments:\n```\nsome hunk\n```\n")
                && ! str_contains($instructions, '- > Quoted summary');
        })
        ->andReturn(YakTask::factory()->create(['parent_task_id' => $root->id]));

    (new FlushSteeringMessagesJob($root->id))->handle(app(FollowUpTaskFactory::class));
});

test('flush passes the distinct reviewer logins to the factory', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/geocodio/geocodio/pull/1']);
    PendingSteeringMessage::queueFor($root, 'first', 'github_review', reviewerLogin: 'alice');
    PendingSteeringMessage::queueFor($root, 'second', 'github_review', reviewerLogin: 'bob');
    PendingSteeringMessage::queueFor($root, 'third', 'github_review', reviewerLogin: 'alice');
    PendingSteeringMessage::queueFor($root, 'fourth', 'slack');

    $this->mock(FollowUpTaskFactory::class)
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (YakTask $parent, string $instructions, string $source, ?string $authorName, array $reRequestReviewFrom) => $reRequestReviewFrom === ['alice', 'bob'])
        ->andReturn(YakTask::factory()->create(['parent_task_id' => $root->id]));

    (new FlushSteeringMessagesJob($root->id))->handle(app(FollowUpTaskFactory::class));
});
