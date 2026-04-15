<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $configDir = sys_get_temp_dir() . '/yak-claude-' . uniqid();
    mkdir($configDir);
    file_put_contents(dirname($configDir) . '/.claude.json', '{}');
    config()->set('yak.sandbox.claude_config_source', $configDir);

    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        'claude --version' => Process::result(output: 'claude v1.0.0'),
        '*claude auth status*' => Process::result(output: 'Authenticated'),
        'incus list*' => Process::result(output: 'task-1'),
        'incus snapshot list*' => Process::result(output: 'ready,2026-04-15'),
    ]);

    Http::fake([
        'api.github.com/repos/*' => Http::response(['name' => 'repo']),
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_test', 'expires_at' => now()->addHour()->toIso8601String()]),
    ]);

    // Disable channel healthchecks by default; individual tests enable Slack if needed
    config([
        'yak.channels.slack.bot_token' => null,
        'yak.channels.slack.signing_secret' => null,
    ]);
});

test('healthcheck command passes when all checks are healthy', function () {
    $this->artisan('yak:healthcheck')
        ->assertSuccessful();
});

test('healthcheck command fails when checks are unhealthy', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        '*claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    $this->artisan('yak:healthcheck')
        ->assertFailed();
});

test('healthcheck command posts to slack on failure when configured', function () {
    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
        '*claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    config([
        'yak.channels.slack.bot_token' => 'xoxb-test-token',
        'yak.channels.slack.signing_secret' => 'test-secret',
    ]);

    Http::fake([
        'slack.com/api/auth.test' => Http::response(['ok' => true, 'team' => 'Yak', 'user' => 'yak-bot']),
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
        '*claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    Http::fake();

    $this->artisan('yak:healthcheck')
        ->assertFailed();

    Http::assertNothingSent();
});

test('healthcheck command is scheduled every 15 minutes', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events())->filter(
        fn ($event) => str_contains($event->command ?? '', 'yak:healthcheck')
    );

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('*/15 * * * *');
});
