<?php

use App\Models\GitHubInstallationToken;
use App\Services\HealthCheck\Channel\GitHubChannelCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config([
        'yak.channels.github.app_id' => '12345',
        'yak.channels.github.private_key' => $privateKey,
        'yak.channels.github.installation_id' => 99999,
    ]);

    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_cached_token',
        'expires_at' => now()->addHour(),
    ]);
});

it('returns Ok when installation repositories endpoint responds', function () {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 7, 'repositories' => []]),
    ]);

    $result = app(GitHubChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('7 repositories');
});

it('returns Error on 401', function () {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response('Bad credentials', 401),
    ]);

    $result = app(GitHubChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('401');
});

it('returns Error when installation_id is not configured', function () {
    config(['yak.channels.github.installation_id' => 0]);

    $result = app(GitHubChannelCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('installation_id');
});
