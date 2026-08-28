<?php

use App\Services\HealthCheck\HealthCheck;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\Registry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * A single controllable check, swapped in for the real registry so a test
 * can drive the command through a failing/recovering sequence without
 * depending on the real system checks.
 */
function fakeHealthCheck(callable $result): HealthCheck
{
    return new class($result) implements HealthCheck
    {
        public function __construct(private $result) {}

        public function id(): string
        {
            return 'claude-auth';
        }

        public function name(): string
        {
            return 'Claude Max Session';
        }

        public function section(): HealthSection
        {
            return HealthSection::System;
        }

        public function run(): HealthResult
        {
            return ($this->result)();
        }
    };
}

function bindHealthCheckRegistry(HealthCheck $check): void
{
    $registry = Mockery::mock(Registry::class);
    $registry->shouldReceive('all')->andReturn([$check]);
    app()->instance(Registry::class, $registry);
}

beforeEach(function () {
    $configDir = sys_get_temp_dir() . '/yak-claude-' . uniqid();
    mkdir($configDir);
    file_put_contents(dirname($configDir) . '/.claude.json', '{}');
    config()->set('yak.sandbox.claude_config_source', $configDir);

    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        'claude --version' => Process::result(output: 'claude v1.0.0'),
        '*claude --model claude-haiku-4-5*' => Process::result(output: 'ok'),
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

test('healthcheck command posts once per outage and once on recovery', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb-test-token',
        'yak.channels.slack.signing_secret' => 'test-secret',
    ]);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    Cache::flush();

    bindHealthCheckRegistry(fakeHealthCheck(fn () => HealthResult::error('down')));

    $this->artisan('yak:healthcheck')->assertExitCode(1);
    $this->artisan('yak:healthcheck')->assertExitCode(1);

    Http::assertSentCount(1);

    bindHealthCheckRegistry(fakeHealthCheck(fn () => HealthResult::ok('Authenticated')));

    $this->artisan('yak:healthcheck')->assertExitCode(0);

    Http::assertSentCount(2);
});

test('healthcheck command includes the held-task count and the runbook in the alert', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb-test-token',
        'yak.channels.slack.signing_secret' => 'test-secret',
    ]);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    Cache::flush();

    bindHealthCheckRegistry(fakeHealthCheck(fn () => HealthResult::error('down')));

    $this->artisan('yak:healthcheck')->assertExitCode(1);

    Http::assertSent(function ($request) {
        return str_contains($request['text'], 'Tasks held in queue:')
            && str_contains($request['text'], 'yak-claude-login');
    });
});
