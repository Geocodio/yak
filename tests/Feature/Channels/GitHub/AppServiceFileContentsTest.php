<?php

use App\Channels\GitHub\AppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
});

function fakeInstallationToken(): array
{
    return ['api.github.com/app/installations/*/access_tokens' => Http::response([
        'token' => 'ghs_token',
        'expires_at' => now()->addHour()->toIso8601String(),
    ])];
}

it('returns the raw contents of a file at a commit', function () {
    $sha = str_repeat('a', 40);
    Http::fake(fakeInstallationToken() + [
        'api.github.com/repos/acme/api/contents/.yak/config.yml*' => Http::response("version: 1\n"),
    ]);

    $contents = app(AppService::class)->getFileContents(99999, 'acme/api', '.yak/config.yml', $sha);

    expect($contents)->toBe("version: 1\n");
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/contents/.yak/config.yml?ref={$sha}")
        && $request->hasHeader('Accept', 'application/vnd.github.raw+json'));
});

it('returns null when the file does not exist', function () {
    Http::fake(fakeInstallationToken() + [
        'api.github.com/repos/acme/api/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    expect(app(AppService::class)->getFileContents(99999, 'acme/api', '.yak/config.yml', 'main'))->toBeNull();
});

it('throws instead of reporting a missing file when GitHub fails', function (int $status) {
    Http::fake(fakeInstallationToken() + [
        'api.github.com/repos/acme/api/contents/*' => Http::response('', $status),
    ]);

    expect(fn () => app(AppService::class)->getFileContents(99999, 'acme/api', '.yak/config.yml', 'main'))
        ->toThrow(RuntimeException::class);
})->with([403, 500, 503]);

it('finds the merged pull request that introduced a commit', function () {
    Http::fake(fakeInstallationToken() + [
        'api.github.com/repos/acme/api/commits/*/pulls' => Http::response([
            ['number' => 7, 'title' => 'Open PR', 'html_url' => 'https://github.com/acme/api/pull/7', 'merged_at' => null],
            ['number' => 1482, 'title' => 'Loosen review limits', 'html_url' => 'https://github.com/acme/api/pull/1482', 'merged_at' => '2026-10-09T10:00:00Z'],
        ]),
    ]);

    expect(app(AppService::class)->findPullRequestForCommit(99999, 'acme/api', str_repeat('b', 40)))
        ->toBe(['number' => 1482, 'title' => 'Loosen review limits', 'url' => 'https://github.com/acme/api/pull/1482']);
});

it('returns no pull request when GitHub cannot list them', function () {
    Http::fake(fakeInstallationToken() + [
        'api.github.com/repos/acme/api/commits/*/pulls' => Http::response('', 500),
    ]);

    expect(app(AppService::class)->findPullRequestForCommit(99999, 'acme/api', str_repeat('b', 40)))->toBeNull();
});
