<?php

use App\Models\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
    fakeYakFiles([]);
});

function migrationCommandFakes(array $overrides = []): array
{
    return $overrides + [
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base', 'commit' => ['tree' => ['sha' => 'tree']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::response(['sha' => 'blob'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree2'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['sha' => 'commit'], 201),
        'api.github.com/repos/acme/api/git/refs' => Http::response(['ref' => 'x'], 201),
        'api.github.com/repos/acme/api/git/refs/heads/*' => Http::response(['ref' => 'x']),
        'api.github.com/repos/acme/api/pulls?*' => Http::response([]),
        'api.github.com/repos/acme/api/pulls' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/api/pull/12'], 201),
        'api.github.com/repos/acme/api/issues/12/labels' => Http::response([]),
    ];
}

function migratableRepository(array $attributes = []): Repository
{
    return Repository::factory()->create($attributes + ['slug' => 'api', 'github_full_name' => 'acme/api', 'default_branch' => 'main', 'is_active' => true]);
}

it('opens a migration pull request and prints its URL', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository();

    $this->artisan('yak:migrate-config')->expectsOutputToContain('https://github.com/acme/api/pull/12')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls') && $request['title'] === 'Move Yak settings into .yak/' && $request['head'] === 'yak/config-migration');
});

it('skips a repository that already has .yak/ files', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository();
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    $this->artisan('yak:migrate-config')->expectsOutputToContain('already has .yak/ files')->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'git/blobs'));
});

it('skips an inactive repository', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository(['is_active' => false]);

    $this->artisan('yak:migrate-config')->assertExitCode(0);

    Http::assertNothingSent();
});

it('lists the files on a dry run and writes nothing', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository();

    $this->artisan('yak:migrate-config', ['--dry-run' => true])->expectsOutputToContain('.yak/config.yml')->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'git/blobs'));
});

it('reports a failing repository, continues, and exits 1', function () {
    Http::fake(migrationCommandFakes(['api.github.com/repos/acme/broken/*' => Http::response(['message' => 'nope'], 500)]));
    migratableRepository(['slug' => 'broken', 'github_full_name' => 'acme/broken']);
    migratableRepository();

    $this->artisan('yak:migrate-config')
        ->expectsOutputToContain('broken:')
        ->expectsOutputToContain('https://github.com/acme/api/pull/12')
        ->assertExitCode(1);
});

it('limits the run to the repository named by --repo', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository();
    migratableRepository(['slug' => 'other', 'github_full_name' => 'acme/other']);

    $this->artisan('yak:migrate-config', ['--repo' => 'api'])->assertExitCode(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'acme/other'));
});

it('fails a repository whose .yak/ could not be read and continues with the next', function () {
    Http::fake(migrationCommandFakes());
    migratableRepository(['slug' => 'broken', 'github_full_name' => 'acme/broken']);
    migratableRepository();
    fakeUnreachableYakRead(['broken']);

    $this->artisan('yak:migrate-config')
        ->expectsOutputToContain('Could not read .yak/ for broken')
        ->expectsOutputToContain('https://github.com/acme/api/pull/12')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'acme/broken'));
});
