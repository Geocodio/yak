<?php

use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use App\Services\RepositoryConfig;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);
    (new ChannelServiceProvider(app()))->boot();
});

it('clears the config head cache on a push to the default branch', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => false,
    ]);
    Cache::put(RepositoryConfig::headCacheKey($repo), str_repeat('1', 40));

    $payload = [
        'ref' => 'refs/heads/main',
        'after' => str_repeat('2', 40),
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
    ])->assertOk();

    expect(Cache::has(RepositoryConfig::headCacheKey($repo)))->toBeFalse();
});

it('keeps the config head cache on a push to another branch', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => false,
    ]);
    Cache::put(RepositoryConfig::headCacheKey($repo), str_repeat('1', 40));

    $payload = [
        'ref' => 'refs/heads/yak/task-1',
        'after' => str_repeat('2', 40),
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
    ])->assertOk();

    expect(Cache::has(RepositoryConfig::headCacheKey($repo)))->toBeTrue();
});
