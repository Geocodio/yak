<?php

use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\ClaudeCliCheck;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\LastTaskCompletedCheck;
use App\Services\HealthCheck\Registry;
use App\Services\HealthCheck\RenderHealthCheck;
use App\Services\HealthCheck\RepositoriesCheck;
use App\Services\HealthCheck\VoiceoverHealthCheck;
use App\Services\HealthCheck\WebhookSignaturesCheck;
use App\Services\VoiceoverGenerator;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

it('last-task-completed returns Ok with no tasks yet', function () {
    $result = (new LastTaskCompletedCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No completed tasks yet');
});

it('last-task-completed includes external id when available', function () {
    YakTask::factory()->success()->create(['external_id' => 'GEO-9999']);

    $result = (new LastTaskCompletedCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('GEO-9999');
});

it('repositories check returns Ok when no active repositories', function () {
    $result = (new RepositoriesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No active repositories');
});

it('claude cli check succeeds with version output', function () {
    Process::fake([
        'claude --version' => Process::result(output: 'claude v1.0.0'),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('claude v1.0.0');
});

it('claude cli check fails when not installed', function () {
    Process::fake([
        'claude --version' => Process::result(exitCode: 127),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
});

/**
 * Create a throwaway Claude config dir (with the sibling .claude.json
 * session file ClaudeAuthCheck requires) and return its path.
 */
function makeClaudeConfigDir(): string
{
    $base = sys_get_temp_dir() . '/yak-auth-' . uniqid();
    $configDir = $base . '/claude';
    mkdir($configDir, 0755, true);
    file_put_contents($base . '/.claude.json', '{}');

    return $configDir;
}

it('probes inference with the api key unset and clears the gate flag on success', function () {
    Cache::put(ClaudeAuthCheck::UNUSABLE_CACHE_KEY, true, 3600);
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect(Cache::has(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->toBeFalse();

    Process::assertRan(fn ($p) => str_contains($p->command, 'env -u ANTHROPIC_API_KEY')
        && str_contains($p->command, 'claude --model claude-haiku-4-5 -p'));
});

it('probes with a 120 second timeout', function () {
    // Regression guard: a too-short timeout kills the CLI mid-refresh and
    // orphans .oauth_refresh.lock for every sandbox sharing the mount.
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    (new ClaudeAuthCheck)->run();

    Process::assertRan(fn ($p) => $p->timeout === 120);
});

it('does not gate the queue on a single failure', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not authenticated', exitCode: 1)]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect(Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->not->toBeTrue();
});

it('gates the queue after two consecutive failures', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not authenticated', exitCode: 1)]);

    (new ClaudeAuthCheck)->run();
    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect(Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->toBeTrue();
    expect($result->detail)->toContain('yak-claude-login');
});

it('the queue gate flag expires after 24 hours', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not authenticated', exitCode: 1)]);

    (new ClaudeAuthCheck)->run();
    (new ClaudeAuthCheck)->run();

    expect(Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->toBeTrue();

    $this->travel(24)->hours();

    expect(Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->not->toBeTrue();
});

it('a success resets the consecutive failure counter', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'not authenticated', exitCode: 1)]);
    (new ClaudeAuthCheck)->run();

    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);
    (new ClaudeAuthCheck)->run();

    Process::fake(['*' => Process::result(output: '', errorOutput: 'not authenticated', exitCode: 1)]);
    $result = (new ClaudeAuthCheck)->run();

    // Second failure overall, but first since the reset — must not gate yet.
    expect($result->status)->toBe(HealthStatus::Error);
    expect(Cache::get(ClaudeAuthCheck::UNUSABLE_CACHE_KEY))->not->toBeTrue();
});

it('classifies an auth-signature failure with the re-authentication runbook', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'invalid_grant', exitCode: 1)]);

    (new ClaudeAuthCheck)->run();
    $result = (new ClaudeAuthCheck)->run();

    expect($result->detail)->toContain('re-authenticate');
    expect($result->detail)->toContain('yak-claude-login');
});

it('classifies a transient failure without re-authentication guidance', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'upstream connect error, 529 overloaded', exitCode: 1)]);

    (new ClaudeAuthCheck)->run();
    $result = (new ClaudeAuthCheck)->run();

    expect($result->detail)->not->toContain('re-authenticate');
    expect($result->detail)->not->toContain('yak-claude-login');
});

it('classifies a missing binary (exit 127) as a transient failure, not an auth failure', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    Process::fake(['*' => Process::result(output: '', errorOutput: 'claude: command not found', exitCode: 127)]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->detail)->not->toContain('re-authenticate');
});

it('classifies a timeout as a transient failure, not an auth failure', function () {
    Cache::forget(ClaudeAuthCheck::UNUSABLE_CACHE_KEY);
    $symfonyProcess = new Symfony\Component\Process\Process(['claude']);
    $symfonyProcess->setTimeout(0.01);

    Process::fake([
        '*' => new ProcessTimedOutException(
            new Symfony\Component\Process\Exception\ProcessTimedOutException($symfonyProcess, Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL),
            Process::result(exitCode: 124),
        ),
    ]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->detail)->not->toContain('re-authenticate');
});

it('claude auth check sweeps a stale oauth refresh lock before running', function () {
    $configDir = makeClaudeConfigDir();
    $lockDir = $configDir . '/.oauth_refresh.lock';
    mkdir($lockDir);
    touch($lockDir, time() - 3600);

    config()->set('yak.sandbox.claude_config_source', $configDir);
    Process::fake(['*' => Process::result(output: '{"loggedIn":true}')]);

    $result = (new ClaudeAuthCheck)->run();

    expect(is_dir($lockDir))->toBeFalse();
    expect($result->status)->toBe(HealthStatus::Ok);
});

it('claude auth check leaves a fresh oauth refresh lock alone', function () {
    // A recent lock likely belongs to an in-flight refresh; deleting it
    // could corrupt a rotation that is about to be persisted.
    $configDir = makeClaudeConfigDir();
    $lockDir = $configDir . '/.oauth_refresh.lock';
    mkdir($lockDir);

    config()->set('yak.sandbox.claude_config_source', $configDir);
    Process::fake(['*' => Process::result(output: '{"loggedIn":true}')]);

    (new ClaudeAuthCheck)->run();

    expect(is_dir($lockDir))->toBeTrue();
});

it('webhook signatures check passes when no failures', function () {
    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No rejected webhooks');
});

it('webhook signatures check reports rejected webhooks', function () {
    Cache::put('webhook-signature-failures:linear', 3);
    Cache::put('webhook-signature-failures:slack', 1);

    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Linear (3)');
    expect($result->detail)->toContain('Slack (1)');
});

it('stores the probe timestamp as a string that survives cache serialization', function () {
    // Regression: `checked_at` was stored as a Carbon instance. The database
    // cache store serializes the payload, and the object came back as
    // __PHP_Incomplete_Class across a process boundary, so HealthRow's
    // Carbon::parse() threw a TypeError and took out the health row. The
    // array cache driver used by the rest of these tests never serializes,
    // which is exactly why this slipped through.
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    (new ClaudeAuthCheck)->run();

    $stored = Cache::get(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY);

    expect($stored['checked_at'])->toBeString();

    $roundTripped = unserialize(serialize($stored));

    expect($roundTripped['checked_at'])->toBe($stored['checked_at']);
    expect(Carbon\Carbon::parse($roundTripped['checked_at']))->toBeInstanceOf(Carbon\Carbon::class);
});

it('video-render is Ok with no renders in the last 24h', function () {
    $result = (new RenderHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No renders in the last 24h');
});

it('video-render is Ok with rendered count when all succeeded', function () {
    VideoMetric::factory()->count(3)->create();

    $result = (new RenderHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('3 rendered, 0 failed (24h)');
});

it('video-render is Error and names the task when a render failed in the last 24h', function () {
    $task = YakTask::factory()->success()->create();
    VideoMetric::factory()->for($task, 'task')->failed()->create();
    VideoMetric::factory()->failed()->create(['created_at' => now()->subDays(2)]);

    $result = (new RenderHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('1 failed (24h)')
        ->and($result->detail)->toContain("Task #{$task->id}")
        ->and($result->detail)->toContain('boom');
});

it('registry includes the video-render check in the system section', function () {
    $ids = array_map(fn ($c) => $c->id(), app(Registry::class)->forSection(HealthSection::System));

    expect($ids)->toContain('video-render');
});

it('voiceover is Ok and off when no api key is configured', function () {
    config()->set('yak.video.elevenlabs.api_key', null);

    $result = (new VoiceoverHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toBe('Off (no ELEVENLABS_API_KEY)');
});

it('voiceover counts lines generated in the last 24h', function () {
    config()->set('yak.video.elevenlabs.api_key', 'k');
    $task = YakTask::factory()->create();
    Artifact::create([
        'yak_task_id' => $task->id,
        'type' => 'file',
        'role' => 'voiceover',
        'filename' => 'intro.mp3',
        'disk_path' => "{$task->id}/vo/intro.mp3",
        'size_bytes' => 10,
    ]);

    $result = (new VoiceoverHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toBe('On · 1 lines generated (24h)');
});

it('voiceover is Error when the last generation failed', function () {
    config()->set('yak.video.elevenlabs.api_key', 'k');
    Cache::put(VoiceoverGenerator::FAILURE_CACHE_KEY, ['message' => 'HTTP 401', 'at' => now()->toIso8601String()], now()->addDay());

    $result = (new VoiceoverHealthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('HTTP 401');
});

it('registry includes the voiceover check in the system section', function () {
    $ids = array_map(fn ($c) => $c->id(), app(Registry::class)->forSection(HealthSection::System));

    expect($ids)->toContain('voiceover');
});
