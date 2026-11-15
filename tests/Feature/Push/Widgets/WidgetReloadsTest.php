<?php

use App\Enums\PushTokenKind;
use App\Jobs\Push\CheckWidgetReloadsJob;
use App\Jobs\Push\SendWidgetReloadJob;
use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\DeviceRevoker;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Push\Widgets\WidgetFingerprint;
use App\Services\Push\Widgets\WidgetReloads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

function widgetTokenFor(User $user, string $token = 'b2'): DevicePushToken
{
    $device = Device::factory()->phone()->forUser($user)->create(['name' => 'Test phone']);

    return DevicePushToken::factory()->create([
        'device_id' => $device->id,
        'kind' => PushTokenKind::Widget,
        'token' => str_repeat($token, 32),
    ]);
}

/** Changes what the person's widgets show, then runs a check. */
function changeAndCheck(User $user): void
{
    Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => 'usage_limit']);
    app(WidgetReloads::class)->check();
}

function sendJobs(): array
{
    return Queue::pushed(SendWidgetReloadJob::class)->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
    Cache::flush();
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->token = widgetTokenFor($this->user);
});

it('sends at most once a minute per device and keeps the last change', function () {
    fakeApns();
    Queue::fake();
    app(WidgetReloads::class)->check();
    expect(sendJobs())->toHaveCount(1); // the first check has no stored state

    $this->travel(10)->seconds();
    changeAndCheck($this->user);
    $this->travel(10)->seconds();
    changeAndCheck($this->user);

    $jobs = sendJobs();
    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->isDelayed)->toBeFalse()
        ->and($jobs[1]->isDelayed)->toBeTrue()
        ->and($jobs[1]->delay->getTimestamp())->toBe(now()->getTimestamp() + 40);

    // second 59: covered by the pending send
    $this->travel(39)->seconds();
    changeAndCheck($this->user);
    expect(sendJobs())->toHaveCount(2);

    // second 60: the delayed send runs and opens a new window
    $this->travel(1)->seconds();
    $jobs[1]->handle(app(WidgetReloads::class), app(ApnsClient::class), app(ApnsConfig::class), app(ApnsPayloads::class), app(ApnsTokenFailures::class));
    expect(apnsRequests('/3/device/'))->toHaveCount(1);

    // second 65: one more send, delayed to second 120
    $this->travel(5)->seconds();
    changeAndCheck($this->user);
    $jobs = sendJobs();
    expect($jobs)->toHaveCount(3)
        ->and($jobs[2]->isDelayed)->toBeTrue()
        ->and($jobs[2]->delay->getTimestamp())->toBe(now()->getTimestamp() + 55);

    // second 120 runs it; second 121 gets a send delayed to 180
    $this->travel(55)->seconds();
    $jobs[2]->handle(app(WidgetReloads::class), app(ApnsClient::class), app(ApnsConfig::class), app(ApnsPayloads::class), app(ApnsTokenFailures::class));
    $this->travel(1)->seconds();
    changeAndCheck($this->user);
    $jobs = sendJobs();
    expect($jobs)->toHaveCount(4)
        ->and($jobs[3]->delay->getTimestamp())->toBe(now()->getTimestamp() + 59);
});

it('skips a step save but checks a status change', function () {
    Queue::fake();
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id]);
    Cache::forget('widget-reloads:check');
    Queue::fake();

    $thread->update(['current_step' => 'Reading files', 'last_activity_at' => now()->addMinute()]);
    expect(Cache::has('widget-reloads:check'))->toBeFalse();

    $thread->update(['blocked_reason' => 'usage_limit']);
    Queue::assertPushed(CheckWidgetReloadsJob::class, 1);

    Cache::forget('widget-reloads:check');
    $thread->update(['blocked_reason' => null]);
    Queue::assertPushed(CheckWidgetReloadsJob::class, 2);
});

it('sends nothing when nothing changed', function () {
    fakeApns();
    app(WidgetReloads::class)->check();
    expect(apnsRequests('/3/device/'))->toHaveCount(1);

    $this->travel(2)->minutes();
    app(WidgetReloads::class)->check();

    expect(apnsRequests('/3/device/'))->toHaveCount(1);
});

it('sends nothing without a key, to a revoked phone or to a phone without a widget token', function () {
    config(['yak.apns.key_path' => null]);
    app(WidgetReloads::class)->check();
    expect(apnsRequests('/3/device/'))->toBeEmpty();

    fakeApns();
    app(DeviceRevoker::class)->revoke($this->token->device);
    app(WidgetReloads::class)->check();
    expect(apnsRequests('/3/device/'))->toBeEmpty();

    $other = User::factory()->create();
    alertTokenFor($other);
    app(WidgetReloads::class)->check();
    expect(apnsRequests('/3/device/'))->toBeEmpty();
});

it('queues no check without a widget token', function () {
    Queue::fake();
    DevicePushToken::query()->delete();
    Cache::forget('widget-reloads:check');

    Thread::factory()->create(['created_by_user_id' => $this->user->id]);

    Queue::assertNothingPushed();
});

it('coalesces changes into one check', function () {
    Queue::fake();
    Cache::forget('widget-reloads:check');
    Thread::factory()->count(10)->create(['created_by_user_id' => $this->user->id]);

    Queue::assertPushed(CheckWidgetReloadsJob::class, 1);
});

it('checks only people with a widget token', function () {
    fakeApns();
    User::factory()->count(20)->create();
    DB::enableQueryLog();

    app(WidgetReloads::class)->check();

    $threadQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "threads"') || str_contains($query['query'], 'from `threads`'))->count();
    $oneFingerprint = (function () {
        DB::flushQueryLog();
        WidgetFingerprint::for($this->user);

        return collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "threads"') || str_contains($query['query'], 'from `threads`'))->count();
    })->call($this);

    expect($threadQueries)->toBe($oneFingerprint);
});

it('deletes a token that Apple says is gone', function () {
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => 'Unregistered'], 410)]);
    app(WidgetReloads::class)->check();

    expect(DevicePushToken::query()->whereKey($this->token->id)->exists())->toBeFalse();
});

it('sends the payload to the widget topic', function () {
    fakeApns();
    app(WidgetReloads::class)->check();

    $request = apnsRequests('/3/device/')[0];
    expect($request->header('apns-push-type'))->toBe(['widgets'])
        ->and($request->header('apns-topic'))->toBe(['com.example.yak.push-type.widgets'])
        ->and($this->token->fresh()->last_sent_at)->not->toBeNull();
});

it('never breaks a model save when the cache fails', function () {
    Exceptions::fake();
    Cache::shouldReceive('has')->andThrow(new RuntimeException('cache down'));

    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id]);

    expect($thread->exists)->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('never sends thread data', function () {
    fakeApns();
    privateThreadOf($this->user, ['title' => 'Private notes on billing']);
    app(WidgetReloads::class)->check();

    $body = json_decode(apnsRequests('/3/device/')[0]->body(), true);

    ContractFixture::assertMatches('app', 'push/widget-reload', $body);
    expect($body)->toBe(['aps' => ['content-changed' => true]]);
});
