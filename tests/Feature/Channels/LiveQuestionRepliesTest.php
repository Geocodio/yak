<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Providers\ChannelServiceProvider;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadEventWindow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryThreadEventWindow;

require_once __DIR__ . '/../../Support/LiveQuestionHelpers.php';

beforeEach(function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['ok' => true, 'data' => ['agentActivityCreate' => ['success' => true]]])]);
    app()->instance(ThreadEventWindow::class, new InMemoryThreadEventWindow);
    app()->forgetInstance(ThreadEventPublisher::class);
});

function liveRunnerCommands($thread, string $type): array
{
    return collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->where('type', $type)->values()->all();
}

function postSlackReply(string $text): void
{
    $secret = 'test-slack-signing-secret';
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test-token', 'signing_secret' => $secret]);
    (new ChannelServiceProvider(app()))->boot();

    $body = slackThreadReplyPayload($text, 'C_LIVE', '1700000000.000100');
    $headers = signSlackPayload($body, $secret);

    test()->call('POST', '/webhooks/slack', content: $body, server: [
        'HTTP_X-Slack-Request-Timestamp' => $headers['X-Slack-Request-Timestamp'],
        'HTTP_X-Slack-Signature' => $headers['X-Slack-Signature'],
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful();
}

it('tells the Slack thread about a live question and answers it from a reply', function () {
    [$thread, , $task] = threadWithRunningTurn();
    $task->update(['source' => 'slack', 'slack_channel' => 'C_LIVE', 'slack_thread_ts' => '1700000000.000100']);

    questionLineFor($thread, [widthQuestion()]);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Clarification && str_contains($job->message, 'Which width?'));

    postSlackReply('2');

    expect((array) liveRunnerCommands($thread, 'answer_question')[0]['answers'])->toBe(['Which width?' => 'Laptop'])
        ->and($task->refresh()->liveClarificationRound())->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Running);
});

it('passes a Slack reply to a multi-question live round to Claude in words', function () {
    [$thread, , $task] = threadWithRunningTurn();
    $task->update(['source' => 'slack', 'slack_channel' => 'C_LIVE', 'slack_thread_ts' => '1700000000.000100']);
    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    postSlackReply('Contacts, on a laptop');

    expect(liveRunnerCommands($thread, 'decline_question')[0]['message'])->toBe('The person replied instead of picking options: Contacts, on a laptop')
        ->and($task->refresh()->liveClarificationRound())->toBeNull();
});

it('answers a live question from a Linear comment and tells the session when it opens', function () {
    $secret = enableLinearChannel();
    LinearOauthConnection::factory()->create(['workspace_id' => TEST_WORKSPACE_ID, 'installer_user_id' => TEST_YAK_ACTOR_ID]);
    [$thread, , $task] = threadWithRunningTurn();
    $task->update(['source' => 'linear', 'linear_agent_session_id' => 'sess-live']);

    questionLineFor($thread, [colourQuestion(), widthQuestion()]);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Clarification);

    $body = (string) json_encode([
        'type' => 'AgentSessionEvent',
        'action' => 'prompted',
        'organizationId' => TEST_WORKSPACE_ID,
        'agentSession' => ['id' => 'sess-live'],
        'agentActivity' => ['content' => ['body' => 'Contacts first']],
    ]);

    $this->call('POST', '/webhooks/linear', content: $body, server: [
        'HTTP_Linear-Event' => 'AgentSessionEvent',
        'HTTP_Linear-Signature' => signLinearPayload($body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ])->assertSuccessful()->assertJson(['handled' => 'clarification_reply']);

    expect(liveRunnerCommands($thread, 'decline_question')[0]['message'])->toBe('The person replied instead of picking options: Contacts first')
        ->and($task->refresh()->liveClarificationRound())->toBeNull();
});
