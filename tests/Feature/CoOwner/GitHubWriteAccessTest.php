<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\CoOwner\GitHubWriteAccess;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
    $this->repository = Repository::factory()->create(['github_full_name' => 'o/r']);
    $this->user = User::factory()->withGitHub()->create();
});

function fakePermission(array|int $role, ?bool $push = null, string $login = 'mhansen'): void
{
    Http::fake(gitHubTokenFake() + [
        'api.github.com/user/4242' => Http::response(['login' => $login]),
        'api.github.com/repos/o/r/collaborators/*/permission' => is_int($role)
            ? Http::response([], $role)
            : Http::response($role + ($push === null ? [] : ['user' => ['permissions' => ['push' => $push]]])),
    ]);
}

it('maps the collaborator role', function (string $role, string $expected) {
    fakePermission(['role_name' => $role]);

    expect(app(GitHubWriteAccess::class)->check($this->repository, $this->user))->toBe($expected);
})->with([['admin', 'write'], ['maintain', 'write'], ['write', 'write'], ['triage', 'read'], ['read', 'read']]);

it('grants write to a custom role with push permission', function () {
    fakePermission(['role_name' => 'deployer'], true);

    expect(app(GitHubWriteAccess::class)->hasWriteAccess($this->repository, $this->user))->toBeTrue();
});

it('treats a 404 as read', function () {
    fakePermission(404);

    expect(app(GitHubWriteAccess::class)->check($this->repository, $this->user))->toBe('read');
});

it('returns unlinked for a user without GitHub', function () {
    Http::fake();

    expect(app(GitHubWriteAccess::class)->check($this->repository, User::factory()->create()))->toBe('unlinked');
    Http::assertNothingSent();
});

it('updates a renamed login and checks that login', function () {
    fakePermission(['role_name' => 'write'], null, 'new name');

    app(GitHubWriteAccess::class)->check($this->repository, $this->user);

    expect($this->user->fresh()->github_login)->toBe('new name');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/collaborators/new%20name/permission'));
});

it('returns error, caches it for a minute and does not cache a long answer', function () {
    fakePermission(500);
    $access = app(GitHubWriteAccess::class);

    expect($access->check($this->repository, $this->user))->toBe('error');
    $access->check($this->repository, $this->user);
    Http::assertSentCount(3); // token, user lookup, permission: the second check is cached

    $this->travel(2)->minutes();
    $access->check($this->repository, $this->user);
    Http::assertSentCount(5);
});

it('caches the answer for 10 minutes and forget clears it', function () {
    fakePermission(['role_name' => 'write']);
    $access = app(GitHubWriteAccess::class);

    $access->check($this->repository, $this->user);
    $access->check($this->repository, $this->user);
    Http::assertSentCount(3);

    $access->forget($this->repository, $this->user);
    $access->check($this->repository, $this->user);
    Http::assertSentCount(5);

    $this->travel(11)->minutes();
    $access->check($this->repository, $this->user);
    Http::assertSentCount(7);
});
