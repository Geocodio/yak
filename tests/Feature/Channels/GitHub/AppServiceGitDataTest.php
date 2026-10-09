<?php

use App\Channels\GitHub\AppService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
});

function gitDataToken(): array
{
    return ['api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_token', 'expires_at' => now()->addHour()->toIso8601String()])];
}

it('commits files onto a new branch based on the default branch head', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base111', 'commit' => ['tree' => ['sha' => 'tree111']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::sequence()->push(['sha' => 'blobA'], 201)->push(['sha' => 'blobB'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree222'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['sha' => 'commit333'], 201),
        'api.github.com/repos/acme/api/git/refs' => Http::response(['ref' => 'refs/heads/yak/config-migration'], 201),
    ]);

    $sha = app(AppService::class)->createBranchWithFiles(99999, 'acme/api', 'main', 'yak/config-migration',
        ['.yak/config.yml' => "version: 1\n", '.yak/AGENTS.md' => "# Rules\n"], 'Move Yak settings into .yak/');

    expect($sha)->toBe('commit333');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/trees')
        && $request['base_tree'] === 'tree111' && count($request['tree']) === 2
        && $request['tree'][0]['mode'] === '100644');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/commits') && $request['parents'] === ['base111']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/refs') && $request['ref'] === 'refs/heads/yak/config-migration' && $request['sha'] === 'commit333');
});

it('force-updates the branch when it already exists', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base111', 'commit' => ['tree' => ['sha' => 'tree111']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::response(['sha' => 'blobA'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree222'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['sha' => 'commit333'], 201),
        'api.github.com/repos/acme/api/git/refs' => Http::response(['message' => 'Reference already exists'], 422),
        'api.github.com/repos/acme/api/git/refs/heads/*' => Http::response(['ref' => 'refs/heads/yak/config-migration']),
    ]);

    app(AppService::class)->createBranchWithFiles(99999, 'acme/api', 'main', 'yak/config-migration', ['.yak/config.yml' => "version: 1\n"], 'msg');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && str_contains($request->url(), '/git/refs/heads/yak/config-migration') && $request['force'] === true && $request['sha'] === 'commit333');
});

it('lists blob paths of a commit tree and gives up on a truncated tree', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/git/trees/aaa*' => Http::response(['truncated' => false, 'tree' => [
            ['path' => 'app', 'type' => 'tree'], ['path' => 'app/Billing/Invoice.php', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/acme/api/git/trees/bbb*' => Http::response(['truncated' => true, 'tree' => []]),
    ]);

    expect(app(AppService::class)->listTreePaths(99999, 'acme/api', 'aaa'))->toBe(['app/Billing/Invoice.php'])
        ->and(app(AppService::class)->listTreePaths(99999, 'acme/api', 'bbb'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'recursive=1'));
});

it('creates a check run and returns null with a warning when the permission is missing', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/check-runs' => Http::sequence()->push(['id' => 42], 201)->push(['message' => 'Resource not accessible by integration'], 403),
    ]);
    Log::spy();

    $payload = ['name' => 'yak / config', 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'success', 'output' => ['title' => 't', 'summary' => 's']];

    expect(app(AppService::class)->createCheckRun(99999, 'acme/api', $payload))->toBe(42)
        ->and(app(AppService::class)->createCheckRun(99999, 'acme/api', $payload))->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Checks'))->once();
});

it('throws without moving the branch when the ref is rejected for another reason', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base111', 'commit' => ['tree' => ['sha' => 'tree111']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::response(['sha' => 'blobA'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree222'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['sha' => 'commit333'], 201),
        'api.github.com/repos/acme/api/git/refs' => Http::response(['message' => 'Validation Failed'], 422),
    ]);

    expect(fn () => app(AppService::class)->createBranchWithFiles(99999, 'acme/api', 'main', 'yak/config-migration', ['.yak/config.yml' => "version: 1\n"], 'msg'))
        ->toThrow(RequestException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

it('does not touch refs when the commit cannot be created', function () {
    Http::fake(gitDataToken() + [
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base111', 'commit' => ['tree' => ['sha' => 'tree111']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::response(['sha' => 'blobA'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree222'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['message' => 'boom'], 500),
    ]);

    expect(fn () => app(AppService::class)->createBranchWithFiles(99999, 'acme/api', 'main', 'yak/config-migration', ['.yak/config.yml' => "version: 1\n"], 'msg'))
        ->toThrow(RequestException::class);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/git/refs'));
});
