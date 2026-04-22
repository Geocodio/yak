<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\GitHubInstallationToken;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    GitHubInstallationToken::create([
        'installation_id' => 99,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);
});

it('returns repositories sorted by pushed_at descending with private and language fields', function () {
    Http::fake([
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => 3,
            'repositories' => [
                [
                    'full_name' => 'acme/older',
                    'name' => 'older',
                    'description' => null,
                    'default_branch' => 'main',
                    'clone_url' => 'https://github.com/acme/older.git',
                    'pushed_at' => '2026-01-01T00:00:00Z',
                    'private' => true,
                    'language' => 'Ruby',
                ],
                [
                    'full_name' => 'acme/newest',
                    'name' => 'newest',
                    'description' => null,
                    'default_branch' => 'main',
                    'clone_url' => 'https://github.com/acme/newest.git',
                    'pushed_at' => '2026-04-15T00:00:00Z',
                    'private' => false,
                    'language' => 'PHP',
                ],
                [
                    'full_name' => 'acme/middle',
                    'name' => 'middle',
                    'description' => null,
                    'default_branch' => 'main',
                    'clone_url' => 'https://github.com/acme/middle.git',
                    'pushed_at' => '2026-03-01T00:00:00Z',
                    'private' => false,
                    'language' => null,
                ],
            ],
        ], 200),
    ]);

    $repos = app(GitHubAppService::class)->listInstallationRepositories(99);

    expect(array_column($repos, 'full_name'))->toBe([
        'acme/newest',
        'acme/middle',
        'acme/older',
    ]);

    expect($repos[0])->toMatchArray([
        'full_name' => 'acme/newest',
        'private' => false,
        'language' => 'PHP',
    ]);

    expect($repos[2])->toMatchArray([
        'full_name' => 'acme/older',
        'private' => true,
        'language' => 'Ruby',
    ]);

    expect($repos[1]['language'])->toBeNull();
});

it('places repos with null pushed_at at the end', function () {
    Http::fake([
        'https://api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                [
                    'full_name' => 'acme/unknown',
                    'name' => 'unknown',
                    'description' => null,
                    'default_branch' => 'main',
                    'clone_url' => 'https://github.com/acme/unknown.git',
                    'pushed_at' => null,
                    'private' => false,
                    'language' => null,
                ],
                [
                    'full_name' => 'acme/recent',
                    'name' => 'recent',
                    'description' => null,
                    'default_branch' => 'main',
                    'clone_url' => 'https://github.com/acme/recent.git',
                    'pushed_at' => '2026-04-01T00:00:00Z',
                    'private' => false,
                    'language' => null,
                ],
            ],
        ], 200),
    ]);

    $repos = app(GitHubAppService::class)->listInstallationRepositories(99);

    expect(array_column($repos, 'full_name'))->toBe([
        'acme/recent',
        'acme/unknown',
    ]);
});

it('throws when GitHub returns a non-2xx for installation token mint', function () {
    GitHubInstallationToken::query()->delete();

    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    app(GitHubAppService::class)->getInstallationToken(42);
})->throws(RequestException::class);

it('throws when GitHub returns a 2xx with an empty token body', function () {
    GitHubInstallationToken::query()->delete();

    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([], 200),
    ]);

    app(GitHubAppService::class)->getInstallationToken(42);

    expect(GitHubInstallationToken::where('installation_id', 42)->exists())->toBeFalse();
})->throws(RuntimeException::class, 'empty installation token');
