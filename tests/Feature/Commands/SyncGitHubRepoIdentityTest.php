<?php

use App\Models\Repository;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
});

it('records the immutable repo id for repositories that still match their GitHub name', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/geocodio/api' => Http::response([
            'id' => 111,
            'full_name' => 'geocodio/api',
            'clone_url' => 'https://github.com/geocodio/api.git',
        ]),
    ]);

    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'github_full_name' => 'geocodio/api',
        'github_repo_id' => null,
    ]);

    $this->artisan('yak:sync-github-repo-identity')->assertSuccessful();

    expect($repo->refresh()->github_repo_id)->toBe(111)
        ->and($repo->github_full_name)->toBe('geocodio/api');
});

it('heals a repository renamed on GitHub by following the redirect from the stale name', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        // GitHub answers a request for the old path with the current repo.
        'api.github.com/repos/geocodio/provisioner' => Http::response([
            'id' => 555,
            'full_name' => 'geocodio/infrastructure',
            'clone_url' => 'https://github.com/geocodio/infrastructure.git',
        ]),
    ]);

    $repo = Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_full_name' => 'geocodio/provisioner',
        'git_url' => 'https://github.com/geocodio/provisioner.git',
        'github_repo_id' => null,
    ]);

    $this->artisan('yak:sync-github-repo-identity')->assertSuccessful();

    $repo->refresh();

    expect($repo->github_repo_id)->toBe(555)
        ->and($repo->github_full_name)->toBe('geocodio/infrastructure')
        ->and($repo->git_url)->toBe('https://github.com/geocodio/infrastructure.git')
        ->and($repo->slug)->toBe('geocodio/provisioner');
});

it('leaves a repository alone when GitHub cannot resolve it', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*' => Http::response([], 404),
    ]);

    $repo = Repository::factory()->create([
        'slug' => 'geocodio/gone',
        'github_full_name' => 'geocodio/gone',
        'github_repo_id' => null,
    ]);

    $this->artisan('yak:sync-github-repo-identity')->assertSuccessful();

    expect($repo->refresh()->github_repo_id)->toBeNull()
        ->and($repo->github_full_name)->toBe('geocodio/gone');
});

it('reports what it would change without writing when run with --dry-run', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/geocodio/provisioner' => Http::response([
            'id' => 555,
            'full_name' => 'geocodio/infrastructure',
            'clone_url' => 'https://github.com/geocodio/infrastructure.git',
        ]),
    ]);

    $repo = Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_full_name' => 'geocodio/provisioner',
        'github_repo_id' => null,
    ]);

    $this->artisan('yak:sync-github-repo-identity --dry-run')
        ->expectsOutputToContain('geocodio/infrastructure')
        ->assertSuccessful();

    expect($repo->refresh()->github_repo_id)->toBeNull()
        ->and($repo->github_full_name)->toBe('geocodio/provisioner');
});
