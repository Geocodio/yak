<?php

use App\Channels\ChannelRegistry;
use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.slack', ['driver' => 'slack', 'bot_token' => 'xoxb-test', 'signing_secret' => 'secret']);
    config()->set('yak.channels.github.installation_id', 4242);
    GitHubInstallationToken::factory()->create(['installation_id' => 4242, 'token' => 'ghs_test', 'expires_at' => now()->addHour()]);
    Http::fake([
        'slack.com/api/users.lookupByEmail*' => Http::response(['ok' => true, 'user' => ['id' => 'U_DM']]),
        'slack.com/*' => Http::response(['ok' => true]),
        'api.github.com/*' => Http::response(['id' => 1]),
    ]);
});

/**
 * @return list<Request>
 */
function slackPosts(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'chat.postMessage'))
        ->values()
        ->all();
}

function notifyAbout(YakTask $task): void
{
    (new SendNotificationJob($task, NotificationType::Result, 'PR created: https://github.com/acme/web/pull/9', personalize: false))
        ->handle(app(ChannelRegistry::class));
}

test('a task of a private thread sends no channel message', function () {
    $thread = Thread::factory()->private()->create();
    $task = YakTask::factory()->success()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
        'slack_user_id' => 'U_REQUESTER',
        'thread_id' => $thread->id,
    ]);

    notifyAbout($task);

    expect(slackPosts())->toBe([]);
});

test('the owner gets a direct message without the task title', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'description' => 'Rotate the Stripe key',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'started_by_user_id' => $owner->id,
        'responsible_user_id' => $owner->id,
        'thread_id' => $thread->id,
    ]);

    notifyAbout($task);

    expect(slackPosts())->toHaveCount(1)
        ->and(slackPosts()[0]['text'])->toBe('Private thread')
        ->and(json_encode(slackPosts()[0]->data()))->not->toContain('Stripe');
});

test('a direct message to someone who may not see the thread is dropped', function () {
    $owner = User::factory()->create();
    $starter = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $starter->id,
        'thread_id' => $thread->id,
    ]);

    notifyAbout($task);

    expect(slackPosts())->toBe([]);
});

test('a team task is unchanged', function () {
    $starter = User::factory()->create();
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'description' => 'Fix null postal code',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $starter->id,
        'thread_id' => $thread->id,
    ]);

    notifyAbout($task);

    expect(slackPosts())->toHaveCount(1)
        ->and(slackPosts()[0]['text'])->toBe('PR ready: Fix null postal code');
});
