<?php

use App\Enums\TaskRunKind;
use App\Enums\TurnMessageKind;
use App\Enums\TurnState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

/**
 * @return array{0: Thread, 1: YakTask}
 */
function threadReadyForNextTurn(string $headDescription = 'Original request'): array
{
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel', 'setEffort', 'kill')->byDefault();
    [$thread, $task] = threadWithTask();
    $task->forceFill(['description' => $headDescription])->save();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed, 'finished_at' => now()]);

    return [$thread, $task];
}

function conversationOf(Thread $thread): array
{
    return array_values(array_filter(ThreadMessagesData::for($thread), fn (array $message): bool => $message['id'] !== 'initial-message'));
}

it('shows a message typed while a turn ran as its own message once the queued turn starts', function () {
    [$thread, $task] = threadReadyForNextTurn();
    $ada = User::factory()->create(['name' => 'Ada Author']);
    PendingSteeringMessage::queueFor($task, 'Then update the docs', 'thread', authorName: 'Ada Author', authorUserId: $ada->id);

    $run = app(ThreadTurns::class)->start($task, '', null, null, TaskRunKind::FollowUp);
    $rows = conversationOf($thread);

    expect($run->prompt)->toBe('- Then update the docs')
        ->and($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['text' => 'Then update the docs', 'authorName' => 'Ada Author', 'via' => null, 'isReply' => false, 'state' => 'pending']);
});

it('shows every drained message, then the message that started the turn', function () {
    [$thread, $task] = threadReadyForNextTurn();
    PendingSteeringMessage::queueFor($task, 'First, typed', 'thread', authorName: 'Ada Author');
    PendingSteeringMessage::queueFor($task, 'Second, from Slack', 'slack', authorName: 'Grace Hopper');
    PendingSteeringMessage::queueFor($task, 'Yak note', 'system');

    $run = app(ThreadTurns::class)->start($task, 'And the new one', null, null, TaskRunKind::FollowUp, shownText: 'And the new one');
    $rows = conversationOf($thread);

    expect($run->prompt)->toContain('First, typed', 'Second, from Slack', 'Yak note', 'And the new one')
        ->and(array_column($rows, 'text'))->toBe(['First, typed', 'Second, from Slack', 'And the new one'])
        ->and($rows[0]['via'])->toBeNull()
        ->and($rows[1])->toMatchArray(['authorName' => 'Grace Hopper', 'isReply' => true])
        ->and($rows[1]['via'])->toMatchArray(['key' => 'slack', 'label' => 'Slack'])
        ->and(ThreadMessagesData::for($thread))->each(fn ($message) => $message->toHaveKeys(['id', 'uuid', 'text', 'authorName', 'via', 'isReply', 'state', 'sentAt', 'attachments', 'attachmentIds']));
});

it('shows a Slack reply that starts a turn alone, never the head task description', function () {
    [$thread, $task] = threadReadyForNextTurn('Described by the head task');
    PendingSteeringMessage::queueFor($task, 'Please also cover the API', 'slack', authorName: 'Grace Hopper');

    app(ThreadTurns::class)->start($task, '', null, null, TaskRunKind::FollowUp);
    $texts = array_column(conversationOf($thread), 'text');

    expect($texts)->toBe(['Please also cover the API']);
});

it('shows the queued text, not a follow-up head task description that differs from the root', function () {
    [$thread, $root] = threadReadyForNextTurn('Root description');
    $head = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id, 'description' => 'Follow-up description']);
    PendingSteeringMessage::queueFor($head, 'Queued reply', 'linear', authorName: 'Linus');

    app(ThreadTurns::class)->start($head, '', null, null, TaskRunKind::FollowUp);
    $texts = array_column(conversationOf($thread), 'text');

    expect($texts)->toBe(['Queued reply']);
});

it('keeps the files of a drained message on its row', function () {
    [$thread, $task] = threadReadyForNextTurn();
    $queued = PendingSteeringMessage::queueFor($task, 'See the screenshot', 'thread', authorName: 'Ada Author');
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => $task->id, 'pending_steering_message_id' => $queued->id]);

    app(ThreadTurns::class)->start($task, '', null, null, TaskRunKind::FollowUp);
    $rows = conversationOf($thread);

    expect($rows)->toHaveCount(1)->and($rows[0]['attachmentIds'])->toBe([$attachment->id]);
});

it('keeps the source and author of a steered channel message after its pending row is deleted', function () {
    [$thread, $task] = threadReadyForNextTurn();
    $queued = PendingSteeringMessage::queueFor($task, 'From Linear', 'linear', authorName: 'Linus');
    app(ThreadTurns::class)->start($task, '', null, null, TaskRunKind::FollowUp);

    expect(conversationOf($thread)[0])->toMatchArray(['authorName' => 'Linus', 'isReply' => true])
        ->and(PendingSteeringMessage::query()->whereKey($queued->id)->exists())->toBeFalse()
        ->and(TurnMessageKind::Steer->value)->toBe('steer');
});
