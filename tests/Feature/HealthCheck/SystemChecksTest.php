<?php

use App\Enums\ClaudeAccountStatus;
use App\Models\Artifact;
use App\Models\ClaudeAccount;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\HealthCheck\ClaudeAccountsCheck;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\LastTaskCompletedCheck;
use App\Services\HealthCheck\Registry;
use App\Services\HealthCheck\RenderHealthCheck;
use App\Services\HealthCheck\RepositoriesCheck;
use App\Services\HealthCheck\VoiceoverHealthCheck;
use App\Services\HealthCheck\WebhookSignaturesCheck;
use App\Services\VoiceoverGenerator;
use Illuminate\Support\Facades\Cache;

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

it('claude accounts is Error with no accounts', function () {
    $result = (new ClaudeAccountsCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->detail)->toContain('No usable Claude account');
});

it('claude accounts is Error when every account is invalid or expired', function () {
    ClaudeAccount::factory()->create(['status' => ClaudeAccountStatus::Invalid]);
    ClaudeAccount::factory()->create(['expires_at' => now()->subDay()]);

    expect((new ClaudeAccountsCheck)->run()->status)->toBe(HealthStatus::Error);
});

it('claude accounts is Ok with one usable account among unusable ones', function () {
    ClaudeAccount::factory()->create(['status' => ClaudeAccountStatus::Invalid]);
    ClaudeAccount::factory()->create();

    $result = (new ClaudeAccountsCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->detail)->toBe('1 usable account');
});

it('registry replaces the old claude checks with claude accounts', function () {
    $ids = array_map(fn ($check) => $check->id(), app(Registry::class)->forSection(HealthSection::System));

    expect($ids)->toContain('claude-accounts')->not->toContain('claude-cli')->not->toContain('claude-auth');
});
