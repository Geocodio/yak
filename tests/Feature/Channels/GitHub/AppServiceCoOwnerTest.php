<?php

use App\Channels\GitHub\AppService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
});

it('reads the collaborator role and treats a 404 as no role', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/o/r/collaborators/alice/permission' => Http::response(['role_name' => 'write']),
        'api.github.com/repos/o/r/collaborators/bob/permission' => Http::response([], 404),
    ]);

    expect(app(AppService::class)->collaboratorRole(99999, 'o/r', 'alice'))->toBe('write')
        ->and(app(AppService::class)->collaboratorRole(99999, 'o/r', 'bob'))->toBeNull();
});

it('throws on a collaborator permission server error', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*' => Http::response([], 500)]);

    app(AppService::class)->collaboratorRole(99999, 'o/r', 'alice');
})->throws(RequestException::class);

it('resolves the current login from a GitHub user id', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/user/4242' => Http::response(['login' => 'renamed']),
        'api.github.com/user/1' => Http::response([], 404),
    ]);

    expect(app(AppService::class)->loginForUserId(99999, 4242))->toBe('renamed')
        ->and(app(AppService::class)->loginForUserId(99999, 1))->toBeNull();
});

it('collects team members across pages', function () {
    $firstPage = array_map(fn (int $i): array => ['login' => "user{$i}"], range(1, 100));
    Http::fake(gitHubTokenFake() + [
        'api.github.com/orgs/acme/teams/core/members*' => Http::sequence()
            ->push($firstPage)
            ->push([['login' => 'last']]),
    ]);

    $logins = app(AppService::class)->listTeamMemberLogins(99999, 'acme', 'core');

    expect($logins)->toHaveCount(101)->and($logins[100])->toBe('last');
});

it('returns null for a team it cannot read', function (int $status) {
    Http::fake(gitHubTokenFake() + ['api.github.com/orgs/*' => Http::response([], $status)]);

    expect(app(AppService::class)->listTeamMemberLogins(99999, 'acme', 'core'))->toBeNull();
})->with([403, 404]);

it('encodes each segment of a deleted branch and keeps the slashes', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*' => Http::response('', 204)]);

    expect(app(AppService::class)->deleteBranch(99999, 'o/r', 'yak/fix-a b'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/repos/o/r/git/refs/heads/yak/fix-a%20b'));
});

it('treats a missing reference as deleted', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*' => Http::response(['message' => 'Reference does not exist'], 422)]);

    expect(app(AppService::class)->deleteBranch(99999, 'o/r', 'yak/x'))->toBeTrue();
});
