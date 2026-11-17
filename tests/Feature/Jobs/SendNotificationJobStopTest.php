<?php

use App\Channels\ChannelRegistry;
use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\StopMessage;
use App\Services\Tasks\StopNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test', 'signing_secret' => 'secret']);
    config()->set('yak.channels.linear.webhook_secret', 'linear-secret');
    LinearOauthConnection::factory()->create();
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U_DM']]),
        'slack.com/*' => Http::response(['ok' => true]),
        'api.linear.app/*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]]),
    ]);
});

test('a stop is sent verbatim with a message per destination and no personality rewrite', function () {
    $starter = User::factory()->create();
    $task = YakTask::factory()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'session-1',
        'external_url' => 'https://linear.app/acme/issue/ENG-1',
        'description' => 'Add CSV export',
        'started_by_user_id' => $starter->id,
    ]);
    $stop = TaskStop::yakSideProblem('boom');

    (new SendNotificationJob($task, NotificationType::Error, $stop->summary, personalize: false, stop: $stop))
        ->handle(app(ChannelRegistry::class));

    $linearBodies = Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'api.linear.app'))
        ->map(fn (Request $request): mixed => $request['variables']['input']['content']['body'] ?? null)
        ->filter()
        ->values();
    $slackPost = Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->first(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage'));

    expect($linearBodies->all())->toBe([StopMessage::forSourceChannel($task, $stop)])
        ->and($slackPost['text'])->toBe('Stopped: Add CSV export')
        ->and(json_encode($slackPost->data()))->toContain('Reply in the Linear issue to try again');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic'));
});

test('a system task records the stop and sends nothing', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['source' => 'system']);

    StopNotifier::notify($task, TaskStop::yakSideProblem('boom'));

    expect($task->fresh()->stop_summary)->toBe('Yak ran into a problem on its side and stopped before finishing.');
    Queue::assertNothingPushed();
});

test('a normal task records the stop and queues one notification carrying it', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['source' => 'slack']);
    $stop = TaskStop::yakSideProblem('boom');

    StopNotifier::notify($task, $stop);

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->stop === $stop && $job->personalize === false);
});
