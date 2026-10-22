<?php

use App\Channels\Slack\CoOwnerMessenger;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\NotificationType;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
});

function fakeSlackPosts(): void
{
    $counter = 0;
    Http::fake(['slack.com/api/*' => function (Request $request) use (&$counter) {
        $counter++;

        return Http::response(['ok' => true, 'channel' => $request['channel'] === 'U_FAIL' ? 'x' : 'D' . $request['channel'], 'ts' => "100.{$counter}"]);
    }]);
}

it('posts the thread reply and one DM per asked user and stores their ts', function () {
    fakeSlackPosts();
    $ada = User::factory()->create(['slack_user_id' => 'UADA']);
    $sam = User::factory()->create(['slack_user_id' => 'USAM']);
    $request = heldRequest(['asked_user_ids' => [$sam->id, $ada->id]], ['slack_channel' => 'C1', 'slack_thread_ts' => '55.66']);

    app(CoOwnerMessenger::class)->held($request);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'chat.postMessage') && $sent['channel'] === 'C1' && $sent['thread_ts'] === '55.66');
    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'USAM');
    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'UADA');
    $request->refresh();
    expect($request->thread_message_ts)->toBe('100.1')
        ->and($request->direct_messages)->toBe([
            ['userId' => $sam->id, 'channel' => 'DUSAM', 'ts' => '100.2'],
            ['userId' => $ada->id, 'channel' => 'DUADA', 'ts' => '100.3'],
        ]);
});

it('updates every DM in place when the request resolves', function () {
    fakeSlackPosts();
    $sam = User::factory()->create(['name' => 'Sam Lee']);
    $request = heldRequest([
        'status' => CoOwnerRequestStatus::Rejected,
        'decided_by_user_id' => $sam->id,
        'decided_at' => now(),
        'direct_messages' => [['userId' => $sam->id, 'channel' => 'D123', 'ts' => '9.9']],
    ], ['slack_channel' => 'C1', 'slack_thread_ts' => '55.66']);

    app(CoOwnerMessenger::class)->resolved($request);

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'chat.update')
        && $sent['channel'] === 'D123' && $sent['ts'] === '9.9'
        && str_starts_with($sent['blocks'][0]['text']['text'], 'Rejected by Sam Lee'));
    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'chat.postMessage') && $sent['thread_ts'] === '55.66'
        && str_contains($sent['blocks'][0]['text']['text'], 'rejected this change'));
});

it('skips the thread reply for tasks without a Slack thread', function () {
    fakeSlackPosts();
    $sam = User::factory()->create(['slack_user_id' => 'USAM']);
    $request = heldRequest(['asked_user_ids' => [$sam->id]], ['slack_channel' => null, 'slack_thread_ts' => null]);

    app(CoOwnerMessenger::class)->held($request);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'USAM');
    expect($request->refresh()->thread_message_ts)->toBeNull();
});

it('sends the thread text as a Progress notification for non-Slack sources', function () {
    Queue::fake();
    Http::fake();
    $request = heldRequest(['asked_user_ids' => []], ['source' => 'linear']);

    app(CoOwnerMessenger::class)->held($request);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => $job->type === NotificationType::Progress
        && $job->personalize === false
        && str_contains($job->message, 'It needs a co-owner before I open a PR.'));
    Http::assertNothingSent();
});

it('logs and continues when one DM fails', function () {
    Log::shouldReceive('channel->warning')->once();
    Http::fake([
        'slack.com/api/*' => Http::sequence()
            ->push(['ok' => false, 'error' => 'channel_not_found'])
            ->push(['ok' => true, 'channel' => 'DOK', 'ts' => '1.1']),
    ]);
    $bad = User::factory()->create(['slack_user_id' => 'UBAD']);
    $good = User::factory()->create(['slack_user_id' => 'UGOOD']);
    $request = heldRequest(['asked_user_ids' => [$bad->id, $good->id]], ['slack_channel' => null, 'slack_thread_ts' => null]);

    app(CoOwnerMessenger::class)->held($request);

    expect($request->refresh()->direct_messages)->toBe([['userId' => $good->id, 'channel' => 'DOK', 'ts' => '1.1']]);
});

it('sends the reminder DM and thread note, and nothing when nobody was asked', function () {
    fakeSlackPosts();
    $sam = User::factory()->create(['slack_user_id' => 'USAM']);
    $asked = heldRequest(['asked_user_ids' => [$sam->id]], ['slack_channel' => 'C1', 'slack_thread_ts' => '55.66']);

    app(CoOwnerMessenger::class)->remind($asked);

    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'USAM' && str_contains(json_encode($sent['blocks']), 'has been held for 2 days'));
    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'C1' && str_contains($sent['blocks'][0]['text']['text'], 'Still waiting for a co-owner. I asked'));

    Http::fake();
    $nobody = heldRequest(['asked_user_ids' => []], ['slack_channel' => 'C1', 'slack_thread_ts' => '55.66']);
    app(CoOwnerMessenger::class)->remind($nobody);
    Http::assertSentCount(1);
});

it('answers a click through response_url as an ephemeral message', function () {
    Http::fake();

    app(CoOwnerMessenger::class)->ephemeral('https://hooks.slack.com/actions/T/1/x', 'Already decided.');

    Http::assertSent(fn (Request $sent) => $sent->url() === 'https://hooks.slack.com/actions/T/1/x'
        && $sent['response_type'] === 'ephemeral' && $sent['replace_original'] === false && $sent['text'] === 'Already decided.');
});

it('runs the messenger for the event the job carries', function () {
    fakeSlackPosts();
    $request = heldRequest(['asked_user_ids' => []], ['slack_channel' => 'C1', 'slack_thread_ts' => '55.66']);

    SendCoOwnerMessagesJob::dispatchSync($request, 'held');

    expect($request->refresh()->thread_message_ts)->toBe('100.1');
});

it('rewrites the early warning in place from the stored state', function () {
    Http::fake(['slack.com/api/*' => Http::response(['ok' => true])]);
    $task = YakTask::factory()->create(['early_warning' => [
        'state' => 'declined', 'areas' => [['name' => 'Billing', 'risk' => 'critical']],
        'slackChannel' => 'C9', 'slackTs' => '9.9', 'taggedAt' => null,
    ]]);

    app(CoOwnerMessenger::class)->updateEarlyWarning($task);
    app(CoOwnerMessenger::class)->updateEarlyWarning($task, 'C1', '1.1');

    Http::assertSent(fn (Request $sent) => str_ends_with($sent->url(), 'chat.update') && $sent['channel'] === 'C9' && $sent['ts'] === '9.9'
        && str_contains($sent['blocks'][0]['text']['text'], 'if the change is held.') && count($sent['blocks']) === 2);
    Http::assertSent(fn (Request $sent) => $sent['channel'] === 'C1' && $sent['ts'] === '1.1');
});
