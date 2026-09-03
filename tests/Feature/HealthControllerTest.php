<?php

use App\Models\User;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\HealthAction;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        '*ls-remote*' => Process::result(output: 'abc123 HEAD'),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    // Disable all channels by default; individual tests enable what they need
    config([
        'yak.channels.slack.bot_token' => null,
        'yak.channels.slack.signing_secret' => null,
        'yak.channels.linear.webhook_secret' => null,
        'yak.channels.sentry.auth_token' => null,
        'yak.channels.sentry.webhook_secret' => null,
        'yak.channels.sentry.org_slug' => null,
        'yak.channels.drone.url' => null,
        'yak.channels.drone.token' => null,
        'yak.channels.github.app_id' => null,
        'yak.channels.github.private_key' => null,
        'yak.channels.github.webhook_secret' => null,
    ]);
});

/**
 * Requests one of the deferred result props via an Inertia partial reload,
 * the same way the frontend's `<Deferred data="systemResults">` follow-up
 * request would. Results are split per section so the System rows can paint
 * without waiting on the slower third-party channel checks. Returns raw JSON
 * (the response has no `page` view, so the `assertInertia()` macro -- which
 * asserts against the full-page HTML response -- does not apply; use
 * `assertJsonPath('props.systemResults...')` against the returned response
 * instead).
 */
function requestHealthResults(string $prop = 'systemResults')
{
    $version = test()->get(route('health'), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

    return test()->get(route('health'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Health/Index',
        'X-Inertia-Partial-Data' => $prop,
    ]);
}

it('renders the health page with system and channel checks', function () {
    $this->get(route('health'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Health/Index')
            ->has('systemChecks', 10)
            ->where('channelChecks', []));
});

it('includes only enabled channels in channelChecks', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb',
        'yak.channels.slack.signing_secret' => 'sig',
    ]);

    $this->get(route('health'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('channelChecks', 2)
            ->where('channelChecks.0.id', 'slack')
            ->where('channelChecks.1.id', 'slack-interactivity'));
});

it('resolves the deferred result for a system check', function () {
    requestHealthResults()
        ->assertOk()
        ->assertJsonPath('props.systemResults.queue-worker.status', 'ok')
        ->assertJsonPath('props.systemResults.queue-worker.message', 'Running, PID 12345');
});

it('caches the result for 60 seconds', function () {
    requestHealthResults()->assertJsonPath('props.systemResults.queue-worker.message', 'Running, PID 12345');

    Process::fake(['pgrep *' => Process::result(exitCode: 1)]);

    requestHealthResults()->assertJsonPath('props.systemResults.queue-worker.message', 'Running, PID 12345');
});

it('refresh all clears the cache for every check', function () {
    requestHealthResults();
    expect(Cache::get('health:check:queue-worker'))->not->toBeNull();

    $this->post(route('health.refresh'))->assertRedirect();

    expect(Cache::get('health:check:queue-worker'))->toBeNull();
});

it('refresh one clears the cache for a single check and re-runs it', function () {
    requestHealthResults()->assertJsonPath('props.systemResults.queue-worker.message', 'Running, PID 12345');

    Process::fake(['pgrep *' => Process::result(exitCode: 1)]);

    $this->post(route('health.check.refresh', ['check' => 'queue-worker']))->assertRedirect();

    requestHealthResults()->assertJsonPath('props.systemResults.queue-worker.status', 'fail');
});

it('renders the stored claude-auth result without probing inference', function () {
    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::ok('Authenticated'),
        'checked_at' => now(),
    ], now()->addDay());

    requestHealthResults()->assertJsonPath('props.systemResults.claude-auth.status', 'ok');

    Process::assertNotRan(fn ($process) => str_contains($process->command, '--model claude-haiku'));
});

it('reports not yet probed for claude-auth when no scheduled result exists', function () {
    Cache::forget(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY);

    requestHealthResults()->assertJsonPath('props.systemResults.claude-auth.status', 'warn');

    Process::assertNotRan(fn ($process) => str_contains($process->command, '--model claude-haiku'));
});

it('degrades a stale claude-auth probe result to a warning', function () {
    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::ok('Authenticated'),
        'checked_at' => now()->subMinutes(40),
    ], now()->addDay());

    requestHealthResults()->assertJsonPath('props.systemResults.claude-auth.status', 'warn');
});

it('preserves a stale claude-auth Error status and its re-authentication action instead of downgrading to a warning', function () {
    $action = new HealthAction('Re-authenticate', 'https://example.test/reauth');

    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::error('Not authenticated — please re-authenticate', $action),
        'checked_at' => now()->subMinutes(40),
    ], now()->addDay());

    requestHealthResults()
        ->assertJsonPath('props.systemResults.claude-auth.status', 'fail')
        ->assertJsonPath('props.systemResults.claude-auth.actionLabel', 'Re-authenticate')
        ->assertJsonPath('props.systemResults.claude-auth.actionUrl', 'https://example.test/reauth');
});

it('does not flag a claude-auth probe result just under the staleness threshold', function () {
    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::ok('Authenticated'),
        'checked_at' => now()->subMinutes(30),
    ], now()->addDay());

    requestHealthResults()->assertJsonPath('props.systemResults.claude-auth.status', 'ok');
});

it('renders the age of a fresh claude-auth probe result', function () {
    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::ok('Authenticated'),
        'checked_at' => now()->subMinutes(5),
    ], now()->addDay());

    $response = requestHealthResults()->assertJsonPath('props.systemResults.claude-auth.status', 'ok');

    expect($response->json('props.systemResults.claude-auth.checkedAgo'))->not->toBeNull();
    expect($response->json('props.systemResults.claude-auth.message'))
        ->toContain('Authenticated')
        ->toContain('ago');
});

it('defers system and channel results as independently loadable props', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb',
        'yak.channels.slack.signing_secret' => 'sig',
    ]);

    // Asking for the system section must not resolve the channel section --
    // that separation is what lets System paint before the slow channel
    // checks finish.
    $response = requestHealthResults('systemResults');

    $response->assertJsonPath('props.systemResults.queue-worker.status', 'ok');

    expect($response->json('props'))->not->toHaveKey('channelResults');

    expect(requestHealthResults('channelResults')->json('props.channelResults'))
        ->toHaveKey('slack');
});

it('requires authentication', function () {
    auth()->logout();

    $this->get(route('health'))->assertRedirect(route('login'));
});
