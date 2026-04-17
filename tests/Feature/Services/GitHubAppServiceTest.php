<?php

use App\Models\GitHubInstallationToken;
use App\Services\GitHubAppService;
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
