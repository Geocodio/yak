<?php

use App\Enums\ThreadShareMode;
use App\Jobs\Push\DeleteLiveActivityChannelJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsProviderToken;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use App\Services\Threads\ThreadSharing;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;

function channelPath(Thread $thread): string
{
    return appThreadPath($thread, '/live-activity-channel');
}

function channelWorld(): array
{
    $owner = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($owner, ['title' => 'Retry failed uploads', 'repo' => 'example/web']);

    return [$owner, $thread, phoneHeadersFor($owner)];
}

function stubChannelLock(Closure $block): void
{
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andReturnUsing($block);
    Cache::partialMock()->shouldReceive('lock')->andReturn($lock);
}

it('creates the channel once and stores it before answering', function () {
    fakeApns();
    [, $thread, $headers] = channelWorld();

    $response = $this->postJson(channelPath($thread), [], $headers)->assertOk()->assertExactJson(['channel' => 'dGVzdC1jaGFubmVs']);

    ContractFixture::assertMatches('app', 'thread-live-activity-channel', $response->json());
    expect($thread->fresh()->live_activity_channel)->toBe('dGVzdC1jaGFubmVs');

    $this->postJson(channelPath($thread), [], $headers)->assertOk()->assertExactJson(['channel' => 'dGVzdC1jaGFubmVs']);
    expect(apnsRequests('/channels'))->toHaveCount(1);
});

it('reads the channel another request created while it waited', function () {
    fakeApns();
    [, $thread, $headers] = channelWorld();
    stubChannelLock(function (int $seconds, Closure $callback) use ($thread) {
        Thread::withoutGlobalScopes()->whereKey($thread->id)->update(['live_activity_channel' => 'other-channel']);

        return $callback();
    });

    $this->postJson(channelPath($thread), [], $headers)->assertOk()->assertExactJson(['channel' => 'other-channel']);
    expect(apnsRequests('/channels'))->toBeEmpty();
});

it('answers 503 when the lock wait runs out', function () {
    fakeApns();
    [, $thread, $headers] = channelWorld();
    stubChannelLock(fn () => throw new LockTimeoutException);

    $this->postJson(channelPath($thread), [], $headers)->assertStatus(503)->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is still setting up live updates for this thread. Try again in a moment.');
    expect(apnsRequests('/channels'))->toBeEmpty();
});

it('answers 503 when Apple is slow', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => fn () => throw new ConnectionException('cURL error 28')]);
    [, $thread, $headers] = channelWorld();

    $this->postJson(channelPath($thread), [], $headers)->assertStatus(503)->assertJsonPath('message', 'Apple did not answer in time. Try again in a minute.');
    expect($thread->fresh()->live_activity_channel)->toBeNull();
});

it('names a setup problem', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response(['reason' => 'BroadcastFeatureNotEnabled'], 400)]);
    [, $thread, $headers] = channelWorld();

    $this->postJson(channelPath($thread), [], $headers)->assertStatus(409)->assertJsonPath('error', 'push_rejected')->assertJsonPath('details.reason', 'BroadcastFeatureNotEnabled');
    expect($thread->fresh()->live_activity_channel)->toBeNull();
});

it('answers 503 after a provider token renewal without a second creation', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::sequence()
        ->push(['reason' => 'ExpiredProviderToken'], 403)
        ->push('', 201, ['apns-channel-id' => 'dGVzdC1jaGFubmVs'])]);
    [, $thread, $headers] = channelWorld();
    $cacheKey = 'apns:provider-token:ABC123DEFG';
    $rejectedToken = app(ApnsProviderToken::class)->current();
    expect(Cache::get($cacheKey))->toBe($rejectedToken);
    $this->travel(1)->second();

    $this->postJson(channelPath($thread), [], $headers)->assertStatus(503)->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Apple did not answer in time. Try again in a minute.');

    expect(apnsRequests('/channels'))->toHaveCount(1)
        ->and($thread->fresh()->live_activity_channel)->toBeNull()
        ->and(Cache::get($cacheKey))->not->toBe($rejectedToken);

    $this->postJson(channelPath($thread), [], $headers)->assertOk()->assertExactJson(['channel' => 'dGVzdC1jaGFubmVs']);
});

it('answers 503 when Apple has no channels left', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response(['reason' => 'TooManyChannels'], 429)]);
    [, $thread, $headers] = channelWorld();

    $this->postJson(channelPath($thread), [], $headers)->assertStatus(503)
        ->assertJsonPath('message', 'This Yak has used up its live update channels for now. Try again later.');
});

it('refuses without a key and for a closed thread', function () {
    fakeApns();
    [, $thread, $headers] = channelWorld();
    config(['yak.apns.key_path' => '']);

    $response = $this->postJson(channelPath($thread), [], $headers)->assertStatus(409)->assertJsonPath('error', 'push_unavailable');
    ContractFixture::assertMatches('app', 'error-push-unavailable', $response->json());

    config(['yak.apns.key_path' => fakeApns()]);
    $thread->forceFill(['closed_at' => now()])->save();
    $this->postJson(channelPath($thread), [], $headers)->assertStatus(409)->assertJsonPath('error', 'thread_closed');
    Http::assertNothingSent();
});

it('lets a share recipient watch', function () {
    fakeApns();
    [$owner, $thread] = channelWorld();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(channelPath($thread), [], phoneHeadersFor($recipient))->assertOk()->assertJsonPath('channel', 'dGVzdC1jaGFubmVs');
});

it('answers a stranger exactly like a missing thread', function (bool $hasKey) {
    fakeApns();
    [, $thread] = channelWorld();
    config($hasKey ? [] : ['yak.apns.key_path' => '']);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson(appThreadPath(new Thread(['public_id' => 'zzzzzzzz']), '/live-activity-channel'), [], $headers)->assertNotFound();
    $this->postJson(channelPath($thread), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson('/api/app/v1/threads/' . $thread->id . '/live-activity-channel', [], $headers)->assertNotFound()->assertExactJson($missing->json());
    expect(apnsRequests('/channels'))->toBeEmpty();
})->with([true, false]);

it('refuses without a token, with a revoked phone and with a computer token', function () {
    fakeApns();
    [$owner, $thread] = channelWorld();
    $this->postJson(channelPath($thread), [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson(channelPath($thread), [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $owner->id], isPhone: false);
    $this->postJson(channelPath($thread), [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    Http::assertNothingSent();
});

it('deletes a channel and clears the column', function () {
    fakeApns();
    [, $thread] = channelWorld();
    $thread->forceFill(['live_activity_channel' => 'dGVzdC1jaGFubmVs'])->save();

    app(LiveActivityChannels::class)->deleteLater($thread);

    expect($thread->fresh()->live_activity_channel)->toBeNull()
        ->and(apnsRequests('/channels')[0]->method())->toBe('DELETE');
});

it('keeps a newer channel when an old delete finishes', function () {
    fakeApns();
    [, $thread] = channelWorld();
    $thread->forceFill(['live_activity_channel' => 'newer-channel'])->save();

    DeleteLiveActivityChannelJob::dispatchSync($thread->id, 'dGVzdC1jaGFubmVs');

    expect($thread->fresh()->live_activity_channel)->toBe('newer-channel');
});

it('reports a refusal to delete without the channel id and keeps the column', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response(['reason' => 'BadChannelId'], 400)]);
    Exceptions::fake();
    [, $thread] = channelWorld();
    $thread->forceFill(['live_activity_channel' => 'dGVzdC1jaGFubmVs'])->save();

    DeleteLiveActivityChannelJob::dispatchSync($thread->id, 'dGVzdC1jaGFubmVs');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'APNs refused to delete a live activity channel: status 400, reason BadChannelId.');
    expect($thread->fresh()->live_activity_channel)->toBe('dGVzdC1jaGFubmVs');
});

it('retries a delete after the backoff of the current attempt', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response('', 503)]);
    [, $thread] = channelWorld();
    $job = new DeleteLiveActivityChannelJob($thread->id, 'dGVzdC1jaGFubmVs');
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);
    $queueJob->shouldReceive('release')->once()->with(30);
    $job->setJob($queueJob);

    $job->handle(app(ApnsClient::class), app(ApnsConfig::class));
});
