<?php

use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadVisibility;
use App\Http\Resources\ThreadMessagesData;
use App\Http\Resources\ThreadPageData;
use App\Jobs\RunFollowUpJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\CoOwner\TaskRequester;
use App\Services\DirectMessageRecipients;
use App\Services\FollowUpTaskFactory;
use App\Services\Threads\ThreadSharing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Helpers/SlackHelpers.php';

function messageFor(YakTask $task, array $runAttributes = []): ThreadTurnMessage
{
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $task->thread_id, ...$runAttributes]);

    return ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'text' => $task->description]);
}

it('names a Slack requester who maps to a Yak user and credits the Slack source', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true])]);
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test-token', 'signing_secret' => 'test-slack-signing-secret']);
    (new ChannelServiceProvider(app()))->boot();
    Repository::factory()->create(['slug' => 'only-repo']);
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'slack_user_id' => 'U_MAPPED']);

    $body = (string) json_encode(['type' => 'event_callback', 'event_id' => 'Ev1', 'event' => ['type' => 'app_mention', 'text' => '<@U_BOT_ID> fix it', 'channel' => 'C1', 'ts' => '1.1', 'user' => 'U_MAPPED']]);
    $headers = signSlackPayload($body, 'test-slack-signing-secret');
    $this->call('POST', '/webhooks/slack', content: $body, server: ['HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'], 'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'], 'CONTENT_TYPE' => 'application/json'])->assertSuccessful();

    $task = YakTask::query()->where('source', 'slack')->firstOrFail();
    expect($task->started_by_user_id)->toBe($ada->id)->and($task->author_user_id)->toBe($ada->id);

    messageFor($task);
    $message = ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id))[0];

    expect($message)->toMatchArray(['authorName' => 'Ada Lovelace', 'authorInitials' => 'AL', 'isReply' => false, 'isRetry' => false, 'retryNumber' => null])
        ->and($message['via'])->toMatchArray(['key' => 'slack', 'label' => 'Slack']);
});

it('shows an unmapped Slack requester by name, never a question mark', function () {
    $task = YakTask::factory()->create(['source' => 'slack', 'author_name' => 'Grace Hopper', 'started_by_user_id' => null]);
    messageFor($task);

    $message = ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id))[0];

    expect($message)->toMatchArray(['authorName' => 'Grace Hopper', 'authorInitials' => 'GH'])
        ->and($message['via']['key'])->toBe('slack');
});

it('falls back to Yak when nobody is known', function () {
    $task = YakTask::factory()->create(['source' => 'sentry', 'author_name' => null, 'started_by_user_id' => null]);
    messageFor($task);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id))[0])->toMatchArray(['authorName' => 'Yak', 'authorInitials' => 'Y']);
});

it('has no via for a dashboard message', function () {
    $task = YakTask::factory()->create(['source' => 'dashboard']);
    messageFor($task);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id))[0]['via'])->toBeNull();
});

it('credits a Linear reply to the person who replied, not the starter', function () {
    Queue::fake();
    $starter = User::factory()->create(['name' => 'Starter Person']);
    $replier = User::factory()->create(['name' => 'Second Person', 'email' => 'second@example.com']);
    $root = YakTask::factory()->success()->create([
        'source' => 'linear', 'pr_url' => 'https://github.com/acme/web/pull/9', 'branch_name' => 'yak/x',
        'started_by_user_id' => $starter->id, 'author_name' => 'Starter Person', 'linear_agent_session_id' => 'sess-1',
    ]);

    $child = app(FollowUpTaskFactory::class)->create($root, 'one more thing', 'linear', authorName: 'Second Person', replier: $replier);
    messageFor($child, ['driver_user_id' => $replier->id]);

    expect($child->started_by_user_id)->toBe($starter->id)->and($child->author_user_id)->toBe($replier->id)->and($child->author_name)->toBe('Second Person')
        ->and(app(TaskRequester::class)->for($child)?->id)->toBe($starter->id);

    $message = ThreadMessagesData::for(Thread::query()->findOrFail($child->thread_id))[0];

    expect($message)->toMatchArray(['authorName' => 'Second Person', 'isReply' => true])
        ->and($message['via']['key'])->toBe('linear');
});

it('stores the replier name only when no Yak user matches', function () {
    Queue::fake();
    $starter = User::factory()->create();
    $root = YakTask::factory()->success()->create([
        'source' => 'linear', 'pr_url' => 'https://github.com/acme/web/pull/9', 'branch_name' => 'yak/x', 'started_by_user_id' => $starter->id,
    ]);

    $child = app(FollowUpTaskFactory::class)->create($root, 'hello', 'linear', authorName: 'Outside Person');

    expect($child->started_by_user_id)->toBe($starter->id)->and($child->author_user_id)->toBeNull()->and($child->author_name)->toBe('Outside Person')
        ->and(app(DirectMessageRecipients::class)->for($child, NotificationType::Result))->toHaveCount(1);
    messageFor($child);
    expect(ThreadMessagesData::for(Thread::query()->findOrFail($child->thread_id))[0]['authorName'])->toBe('Outside Person');
    Queue::assertPushed(RunFollowUpJob::class);
});

it('does not mark a dashboard follow-up as a reply', function () {
    $root = YakTask::factory()->success()->create(['source' => 'slack']);
    $child = YakTask::factory()->create(['source' => 'dashboard', 'parent_task_id' => $root->id, 'thread_id' => $root->thread_id]);
    messageFor($child);

    $message = ThreadMessagesData::for(Thread::query()->findOrFail($child->thread_id))[0];

    expect($message['isReply'])->toBeFalse()->and($message['via'])->toBeNull();
});

it('shows a retry as a slim row without the request text', function () {
    $task = YakTask::factory()->create(['description' => 'Fix the login bug']);
    messageFor($task);
    messageFor($task, ['kind' => TaskRunKind::Retry]);
    messageFor($task, ['kind' => TaskRunKind::Retry]);

    $messages = ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id));

    expect($messages[0]['isRetry'])->toBeFalse()
        ->and($messages[1])->toMatchArray(['isRetry' => true, 'retryNumber' => 1, 'text' => '', 'bodyHtml' => ''])
        ->and($messages[2])->toMatchArray(['isRetry' => true, 'retryNumber' => 2, 'text' => '']);
});

it('lets the driver of an ownerless team thread share it and become its owner', function () {
    $thread = Thread::factory()->create(['visibility' => ThreadVisibility::Team, 'owner_user_id' => null]);
    $user = User::factory()->create();

    expect(ThreadPageData::build($thread, $user)['thread']['canShare'])->toBeTrue();

    app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::Everyone);

    expect($thread->refresh()->owner_user_id)->toBe($user->id);
    expect(ThreadPageData::build($thread, User::factory()->create())['thread']['canShare'])->toBeFalse();
});

it('keeps a private thread owner-only and lets nobody claim an owned team thread', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $private = Thread::factory()->create(['visibility' => ThreadVisibility::Private, 'owner_user_id' => null]);
    $owned = Thread::factory()->create(['visibility' => ThreadVisibility::Team, 'owner_user_id' => $owner->id]);

    expect(ThreadPageData::build($private, $other)['thread']['canShare'])->toBeFalse()
        ->and(ThreadPageData::build($owned, $other)['thread']['canShare'])->toBeFalse()
        ->and(ThreadPageData::build($owned, $owner)['thread']['canShare'])->toBeTrue();
    expect(fn () => app(ThreadSharing::class)->apply($private, $other, ThreadShareMode::Everyone))->toThrow(AuthorizationException::class);
    expect(fn () => app(ThreadSharing::class)->apply($owned, $other, ThreadShareMode::OnlyMe))->toThrow(AuthorizationException::class);
});

it('serves the share dialog data to the driver of an ownerless team thread but not to others on a private thread', function () {
    $user = User::factory()->create();
    $team = Thread::factory()->create(['visibility' => ThreadVisibility::Team, 'owner_user_id' => null]);
    $private = Thread::factory()->create(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id]);

    $this->actingAs($user)->getJson(route('threads.share.show', $team))->assertOk();
    $this->actingAs($user)->getJson(route('threads.share.show', $private))->assertNotFound();
    $this->actingAs($user)->putJson(route('threads.share.update', $team), ['mode' => 'everyone'])->assertOk();
    expect($team->refresh()->owner_user_id)->toBe($user->id);
});

it('keeps the requester as the fallback driver when the replier has no Yak user', function () {
    Queue::fake();
    $starter = User::factory()->create();
    $replier = User::factory()->create();
    $root = YakTask::factory()->success()->create(['source' => 'linear', 'pr_url' => 'https://github.com/acme/web/pull/9', 'branch_name' => 'yak/x', 'started_by_user_id' => $starter->id]);

    $byReplier = app(FollowUpTaskFactory::class)->create($root, 'one', 'linear', authorName: 'Bob', replier: $replier);
    $byOutsider = app(FollowUpTaskFactory::class)->create($root->fresh(), 'two', 'linear', authorName: 'Outside');

    expect($byReplier->author_user_id ?? $byReplier->started_by_user_id)->toBe($replier->id)
        ->and($byOutsider->author_user_id ?? $byOutsider->started_by_user_id)->toBe($starter->id);
});

it('does not make a co-owner who replies in Slack the requester', function () {
    Queue::fake();
    $requester = User::factory()->create();
    $coOwner = User::factory()->create(['slack_user_id' => 'U_CO']);
    $root = YakTask::factory()->success()->create(['source' => 'slack', 'pr_url' => 'https://github.com/acme/web/pull/9', 'branch_name' => 'yak/x', 'started_by_user_id' => $requester->id]);

    $child = app(FollowUpTaskFactory::class)->create($root, 'tweak', 'slack', authorName: $coOwner->name, replier: $coOwner);

    expect(app(TaskRequester::class)->for($child)?->id)->toBe($requester->id)->and($child->author_user_id)->toBe($coOwner->id);
});

it('credits a later dashboard message to its sender, not the head task channel', function () {
    $bob = User::factory()->create(['name' => 'Bob Builder']);
    $root = YakTask::factory()->success()->create(['source' => 'slack', 'author_name' => 'Alice Slack']);
    $head = YakTask::factory()->create(['source' => 'slack', 'parent_task_id' => $root->id, 'thread_id' => $root->thread_id, 'author_name' => 'Eve Reply']);
    messageFor($head);
    messageFor($head, ['driver_user_id' => $bob->id]);

    $messages = ThreadMessagesData::for(Thread::query()->findOrFail($root->thread_id));

    expect($messages[0])->toMatchArray(['authorName' => 'Eve Reply', 'isReply' => true])
        ->and($messages[1])->toMatchArray(['authorName' => 'Bob Builder', 'isReply' => false, 'via' => null]);
});

it('pins the full via object of a Linear-sourced first message', function () {
    $task = YakTask::factory()->create(['source' => 'linear', 'external_id' => 'LINEAR-ENG-12', 'external_url' => 'https://linear.app/acme/issue/ENG-12']);
    messageFor($task);

    expect(ThreadMessagesData::for(Thread::query()->findOrFail($task->thread_id))[0]['via'])
        ->toBe(['key' => 'linear', 'label' => 'Linear', 'reference' => 'ENG-12', 'url' => 'https://linear.app/acme/issue/ENG-12']);
});
