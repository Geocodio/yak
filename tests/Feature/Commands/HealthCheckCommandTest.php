<?php

use App\Services\HealthCheckService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        'git ls-remote *' => Process::result(output: 'abc123 HEAD'),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);
});

test('healthcheck command passes when all checks are healthy', function () {
    $this->artisan('yak:healthcheck')
        ->assertSuccessful();
});

test('healthcheck command fails when checks are unhealthy', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    $this->artisan('yak:healthcheck')
        ->assertFailed();
});

test('healthcheck command posts to slack on failure when configured', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    config([
        'yak.channels.slack.bot_token' => 'xoxb-test-token',
        'yak.channels.slack.signing_secret' => 'test-secret',
    ]);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $this->artisan('yak:healthcheck')
        ->assertFailed();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'chat.postMessage')
            && str_contains($request['text'], 'Health Check Failed');
    });
});

test('healthcheck command skips slack when not configured', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    config([
        'yak.channels.slack.bot_token' => null,
        'yak.channels.slack.signing_secret' => null,
    ]);

    Http::fake();

    $this->artisan('yak:healthcheck')
        ->assertFailed();

    Http::assertNothingSent();
});

test('webhook signature check reports rejected webhooks', function () {
    Cache::put('webhook-signature-failures:LinearWebhookController', 3);
    Cache::put('webhook-signature-failures:SlackWebhookController', 1);

    $result = (new HealthCheckService)->checkWebhookSignatures();

    expect($result['healthy'])->toBeFalse();
    expect($result['detail'])->toContain('Linear (3)');
    expect($result['detail'])->toContain('Slack (1)');
});

test('webhook signature check passes when no failures', function () {
    Cache::forget('webhook-signature-failures:LinearWebhookController');

    $result = (new HealthCheckService)->checkWebhookSignatures();

    expect($result['healthy'])->toBeTrue();
    expect($result['detail'])->toBe('No rejected webhooks');
});

test('healthcheck command is scheduled every 15 minutes', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events())->filter(
        fn ($event) => str_contains($event->command ?? '', 'yak:healthcheck')
    );

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('*/15 * * * *');
});
