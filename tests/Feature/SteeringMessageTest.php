<?php

use App\DataTransferObjects\ThreadEntry;
use App\Enums\SteeringMode;
use App\Enums\TaskStatus;
use App\Jobs\FlushSteeringMessagesJob;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\ThreadBuilder;
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

test('delivering steered messages hands them to the run and records them in the thread', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $run = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Running]);
    $steered = PendingSteeringMessage::queueFor($run, 'use the v2 endpoint', 'dashboard', mode: SteeringMode::Steer, authorName: 'Ada');
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $steered->id]);
    PendingSteeringMessage::queueFor($run, 'later, add docs', 'dashboard');

    $sent = [];
    $delivered = PendingSteeringMessage::deliverSteeredFor($run, PendingSteeringMessage::steeredFor($run), function (PendingSteeringMessage $message) use (&$sent): bool {
        $sent[] = [$message->text, $message->attachments->pluck('id')->all()];

        return true;
    });

    expect($delivered)->toBe(1)
        ->and($sent)->toBe([['use the v2 endpoint', [$attachment->id]]])
        ->and(PendingSteeringMessage::pluck('text')->all())->toBe(['later, add docs']);

    expect($attachment->fresh())
        ->yak_task_id->toBe($run->id)
        ->pending_steering_message_id->toBeNull()
        ->context->toBe(TaskAttachment::CONTEXT_STEERING);

    $entry = app(ThreadBuilder::class)->build($run)
        ->first(fn (ThreadEntry $entry) => $entry->text === 'use the v2 endpoint');

    expect($entry)->not->toBeNull()
        ->and($entry->kind)->toBe('user')
        ->and($entry->authorName)->toBe('Ada')
        ->and($entry->attachmentIds)->toBe([$attachment->id]);
});

test('a steered message the run could not send keeps waiting, along with those after it', function () {
    $run = YakTask::factory()->create(['status' => TaskStatus::Running]);
    PendingSteeringMessage::queueFor($run, 'first', 'dashboard', mode: SteeringMode::Steer);
    PendingSteeringMessage::queueFor($run, 'second', 'dashboard', mode: SteeringMode::Steer);
    PendingSteeringMessage::queueFor($run, 'third', 'dashboard', mode: SteeringMode::Steer);

    $delivered = PendingSteeringMessage::deliverSteeredFor(
        $run,
        PendingSteeringMessage::steeredFor($run),
        fn (PendingSteeringMessage $message): bool => $message->text === 'first',
    );

    expect($delivered)->toBe(1)
        ->and(PendingSteeringMessage::orderBy('id')->pluck('text')->all())->toBe(['second', 'third'])
        ->and(TaskLog::where('message', ThreadBuilder::STEERING_MESSAGE_LOG)->count())->toBe(1);
});

test('a steered message withdrawn or switched back after it was read is not sent', function () {
    $run = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $withdrawn = PendingSteeringMessage::queueFor($run, 'withdrawn', 'dashboard', mode: SteeringMode::Steer);
    $switched = PendingSteeringMessage::queueFor($run, 'switched', 'dashboard', mode: SteeringMode::Steer);
    $read = PendingSteeringMessage::steeredFor($run);

    $withdrawn->withdraw();
    $switched->update(['mode' => SteeringMode::Queue]);

    $delivered = PendingSteeringMessage::deliverSteeredFor($run, $read, fn (): bool => throw new RuntimeException('nothing should be sent'));

    expect($delivered)->toBe(0)
        ->and(PendingSteeringMessage::pluck('text')->all())->toBe(['switched']);
});

test('a steered message keeps its source in the thread', function () {
    $run = YakTask::factory()->create(['status' => TaskStatus::Running]);
    PendingSteeringMessage::queueFor($run, 'from slack', 'slack', mode: SteeringMode::Steer);

    PendingSteeringMessage::deliverSteeredFor($run, PendingSteeringMessage::steeredFor($run), fn (): bool => true);

    $entry = app(ThreadBuilder::class)->build($run)->first(fn (ThreadEntry $entry) => $entry->text === 'from slack');

    expect($entry->source)->toBe('slack');
});

test('a steered message the run never took still flushes as a follow-up', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'pr_url' => 'https://github.com/geocodio/geocodio/pull/1']);
    PendingSteeringMessage::queueFor($root, 'steered too late', 'dashboard', mode: SteeringMode::Steer);

    $this->mock(FollowUpTaskFactory::class)
        ->shouldReceive('create')
        ->once()
        ->withArgs(fn (YakTask $parent, string $instructions) => str_contains($instructions, 'steered too late'))
        ->andReturn(YakTask::factory()->create(['parent_task_id' => $root->id]));

    (new FlushSteeringMessagesJob($root->id))->handle(app(FollowUpTaskFactory::class));

    expect(PendingSteeringMessage::count())->toBe(0);
});
