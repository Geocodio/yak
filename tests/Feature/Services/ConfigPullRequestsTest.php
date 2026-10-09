<?php

use App\Models\Repository;
use App\Services\ConfigPullRequests;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
});

function configPrFakes(array $overrides = []): array
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

function openConfigPr(Repository $repository): array
{
    return app(ConfigPullRequests::class)->open($repository, 'yak/setup-config', ['.yak/config.yml' => "version: 1\n"], 'Add .yak/', 'Body', 'Add .yak/');
}

it('opens a labelled pull request on the default branch', function () {
    Http::fake(configPrFakes());

    expect(openConfigPr($this->repository))->toBe(['number' => 12, 'url' => 'https://github.com/acme/api/pull/12', 'created' => true]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['head'] === 'yak/setup-config' && $request['base'] === 'main');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/12/labels') && $request['labels'] === ['yak']);
});

it('returns the existing pull request without writing anything', function () {
    Http::fake(configPrFakes(['api.github.com/repos/acme/api/pulls?*' => Http::response([
        ['number' => 9, 'title' => 'Existing', 'html_url' => 'https://github.com/acme/api/pull/9'],
    ])]));

    expect(openConfigPr($this->repository))->toBe(['number' => 9, 'url' => 'https://github.com/acme/api/pull/9', 'created' => false]);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'git/blobs'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
});

it('force-moves a stale branch then opens a new pull request', function () {
    Http::fake(configPrFakes(['api.github.com/repos/acme/api/git/refs' => Http::response(['message' => 'Reference already exists'], 422)]));

    expect(openConfigPr($this->repository)['created'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['force'] === true);
});

it('finds the open config pull request, migration first', function () {
    Http::fake(configPrFakes([
        'api.github.com/repos/acme/api/pulls?head=acme%3Ayak%2Fconfig-migration*' => Http::response([]),
        'api.github.com/repos/acme/api/pulls?head=acme%3Ayak%2Fsetup-config*' => Http::response([
            ['number' => 5, 'title' => 'Setup', 'html_url' => 'https://github.com/acme/api/pull/5'],
        ]),
    ]));

    expect(app(ConfigPullRequests::class)->openPullRequest($this->repository))
        ->toBe(['number' => 5, 'title' => 'Setup', 'url' => 'https://github.com/acme/api/pull/5']);
});

it('throws when the GitHub App is not configured', function () {
    config()->set('yak.channels.github.installation_id', 0);

    expect(fn () => openConfigPr($this->repository))->toThrow(RuntimeException::class, 'GitHub App is not configured');
});
