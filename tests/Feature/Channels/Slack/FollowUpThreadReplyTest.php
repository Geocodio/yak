<?php

use App\Enums\TaskMode;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test-token',
        'signing_secret' => 'test-slack-signing-secret',
    ]);

    (new ChannelServiceProvider(app()))->boot();
});

/*
|--------------------------------------------------------------------------
| Follow-up thread reply on open-PR task
|--------------------------------------------------------------------------
*/

it('creates a follow-up when a thread reply arrives on a task with an open PR', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('Please also add a test', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertPushed(RunFollowUpJob::class, function (RunFollowUpJob $job) use ($task) {
        return $job->task->parent_task_id === $task->id;
    });
});

/*
|--------------------------------------------------------------------------
| Bot message in an open-PR thread is ignored
|--------------------------------------------------------------------------
*/

it('ignores bot messages in an open-PR thread', function () {
    Queue::fake();

    YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('bot reply text', 'C123', '111.222', [
        'event' => ['bot_id' => 'B_BOT'],
    ]);
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunFollowUpJob::class);
});

it('ignores bot_message subtype in an open-PR thread', function () {
    Queue::fake();

    YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('bot reply text', 'C123', '111.222', [
        'event' => ['subtype' => 'bot_message'],
    ]);
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunFollowUpJob::class);
});

/*
|--------------------------------------------------------------------------
| Thread reply while the task is still running → queued as a steering message
|--------------------------------------------------------------------------
*/

it('queues a steering message when a thread reply arrives while the task is still running', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('Please also add a test', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunFollowUpJob::class);
    expect(PendingSteeringMessage::where('root_task_id', $task->id)->where('text', 'Please also add a test')->exists())->toBeTrue();
});

it('reacts with eyes to a thread reply it queues as a steering message', function () {
    Queue::fake();
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

    YakTask::factory()->running()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
    ]);

    $body = slackThreadReplyPayload('Update Cory too', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/reactions.add'
        && $request['channel'] === 'C123'
        && $request['timestamp'] === '1234567899.999999'
        && $request['name'] === 'eyes');
});

/*
|--------------------------------------------------------------------------
| Thread reply with no matching task → ignored
|--------------------------------------------------------------------------
*/

it('ignores a thread reply in a thread with no matching Yak task', function () {
    Queue::fake();

    $body = slackThreadReplyPayload('some reply', 'C_UNKNOWN', '999.999');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunFollowUpJob::class);
});

/*
|--------------------------------------------------------------------------
| Thread reply on a merged-PR task → decline
|--------------------------------------------------------------------------
*/

it('posts a decline notification and dispatches no follow-up when the PR is already merged', function () {
    Queue::fake();

    YakTask::factory()->merged()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('Can you add something?', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertNotPushed(RunFollowUpJob::class);
    Queue::assertPushed(SendNotificationJob::class);
});

/*
|--------------------------------------------------------------------------
| Regression: awaiting_clarification still answers the pending question
|--------------------------------------------------------------------------
*/

it('still answers the pending question for awaiting-clarification tasks (regression)', function () {
    Queue::fake();

    $task = YakTask::factory()->withClarificationQuestions([sampleQuestion('scope', ['Option A', 'Option B'])->toArray()])->create([
        'source' => 'slack',
        'slack_channel' => 'C_CLAR',
        'slack_thread_ts' => '777.888',
    ]);

    $body = slackThreadReplyPayload('Option A', 'C_CLAR', '777.888');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job) => $job->task->id === $task->id);
    Queue::assertNotPushed(RunFollowUpJob::class);
});

it('records the Slack author name on a thread-reply follow-up', function () {
    Queue::fake();

    Http::fake([
        'slack.com/api/users.info*' => Http::response([
            'ok' => true,
            'user' => ['real_name' => 'Michele', 'profile' => ['display_name' => 'Michele']],
        ]),
    ]);

    $task = YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('Please also add a test', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    expect(YakTask::where('parent_task_id', $task->id)->first()->author_name)->toBe('Michele');
});

it('a Slack thread reply on a finished research task creates a research follow-up', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create(['mode' => TaskMode::Research, 'pr_url' => null, 'repo' => 'research-repo', 'session_id' => 'sess_research', 'source' => 'slack', 'slack_channel' => 'C123', 'slack_thread_ts' => '111.222']);

    $body = slackThreadReplyPayload('What about a circuit breaker?', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()->assertJson(['handled' => 'follow_up']);

    Queue::assertPushed(ResearchFollowUpJob::class, fn (ResearchFollowUpJob $job) => $job->task->parent_task_id === $task->id);
});

it('records the Slack user who replied on the follow-up task', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C123',
        'slack_thread_ts' => '111.222',
        'slack_user_id' => 'U_REQUESTER',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'yak/x',
    ]);

    $body = slackThreadReplyPayload('Please also add a test', 'C123', '111.222');
    $headers = signSlackPayload($body, 'test-slack-signing-secret');

    $this->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();

    $child = YakTask::where('parent_task_id', $task->id)->firstOrFail();
    expect($child->slack_user_id)->toBe('U_REQUESTER')
        ->and($child->slack_follow_up_user_id)->toBe('U_REPLY_USER');
});
