<?php

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerResolver;
use App\Services\CoOwner\GitHubWriteAccess;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
    $this->repository = Repository::factory()->create(['github_full_name' => 'o/r']);
    $this->task = YakTask::factory()->create(['repo' => $this->repository->slug, 'started_by_user_id' => null]);
    $GLOBALS['coOwnerAccess'] = [];
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturnUsing(fn ($repository, User $user): string => $GLOBALS['coOwnerAccess'][$user->github_login] ?? 'read');
    });
});

function coOwnerUser(int $id, string $login, string $access = 'write'): User
{
    $GLOBALS['coOwnerAccess'][$login] = $access;

    return User::factory()->withGitHub($id, $login)->create();
}

function coOwnerLogins(array $result): array
{
    return array_map(fn (User $user): string => $user->github_login, $result['users']);
}

it('asks the person who requested changes last first', function () {
    $reviewer = coOwnerUser(1, 'reviewer');
    coOwnerUser(2, 'owner');
    CoOwnerRequest::factory()->create([
        'task_id' => $this->task->id,
        'repository_id' => $this->repository->id,
        'status' => CoOwnerRequestStatus::ChangesRequested,
        'decided_by_user_id' => $reviewer->id,
        'decided_at' => now(),
    ]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@owner']);

    expect(coOwnerLogins($result))->toBe(['reviewer', 'owner'])
        ->and($result['attempts'][0]['kind'])->toBe('changes_requester');
});

it('asks CODEOWNERS logins that are linked with write access', function () {
    coOwnerUser(1, 'alice');

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@alice', '@ghost']);

    expect(coOwnerLogins($result))->toBe(['alice'])
        ->and($result['attempts'])->toBe([
            ['entry' => '@alice', 'kind' => 'login', 'outcome' => 'asked'],
            ['entry' => '@ghost', 'kind' => 'login', 'outcome' => 'not_linked'],
        ]);
});

it('expands a team and asks its linked members', function () {
    coOwnerUser(1, 'alice');
    coOwnerUser(2, 'bob', 'read');
    Http::fake(gitHubTokenFake() + [
        'api.github.com/orgs/acme/teams/core/members*' => Http::response([['login' => 'alice'], ['login' => 'bob'], ['login' => 'stranger']]),
    ]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@acme/core']);

    expect(coOwnerLogins($result))->toBe(['alice'])
        ->and($result['attempts'][0])->toBe(['entry' => '@acme/core', 'kind' => 'team', 'outcome' => 'asked']);
});

it('skips a team when the App lacks Members read and asks the fallback owner', function () {
    $fallback = coOwnerUser(3, 'fallback');
    $this->repository->update(['default_responsible_user_id' => $fallback->id]);
    Http::fake(gitHubTokenFake() + ['api.github.com/orgs/acme/teams/core/members*' => Http::response([], 403)]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@acme/core']);

    expect(coOwnerLogins($result))->toBe(['fallback'])
        ->and($result['attempts'][0]['outcome'])->toBe('team_skipped')
        ->and($result['attempts'][1]['kind'])->toBe('fallback');
});

it('maps an email owner to a Yak user', function () {
    $user = coOwnerUser(1, 'alice');
    $user->update(['email' => 'alice@example.com']);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['Alice@Example.com']);

    expect(coOwnerLogins($result))->toBe(['alice'])
        ->and($result['attempts'][0]['kind'])->toBe('email');
});

it('skips owners without write access', function () {
    coOwnerUser(1, 'reader', 'read');
    coOwnerUser(2, 'broken', 'error');

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@reader', '@broken']);

    expect($result['users'])->toBe([])
        ->and(array_column($result['attempts'], 'outcome'))->toBe(['no_write_access', 'error']);
});

it('returns nobody when no owner or fallback resolves', function () {
    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@ghost', 'not an owner']);

    expect($result['users'])->toBe([])
        ->and(array_column($result['attempts'], 'outcome'))->toBe(['not_linked', 'unknown']);
});

it('never asks the requester', function () {
    $requester = coOwnerUser(1, 'requester');
    coOwnerUser(2, 'other');
    $this->task->update(['started_by_user_id' => $requester->id]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task->fresh(), ['@requester', '@other']);

    expect(coOwnerLogins($result))->toBe(['other']);
});

it('asks at most three people in CODEOWNERS order without duplicates', function () {
    foreach (range(1, 5) as $id) {
        coOwnerUser($id, "user{$id}");
    }

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@user1', '@user2', '@user1', '@user3', '@user4', '@user5']);

    expect(coOwnerLogins($result))->toBe(['user1', 'user2', 'user3']);
});

it('resolves one entry without a task or a cap', function () {
    foreach (range(1, 4) as $id) {
        coOwnerUser($id, "member{$id}");
    }
    Http::fake(gitHubTokenFake() + [
        'api.github.com/orgs/acme/teams/core/members*' => Http::response(array_map(fn (int $id): array => ['login' => "member{$id}"], range(1, 4))),
    ]);

    $result = app(CoOwnerResolver::class)->resolveEntry($this->repository, '@acme/core');

    expect($result['kind'])->toBe('team')
        ->and($result['outcome'])->toBe('asked')
        ->and($result['users'])->toHaveCount(4);
});

it('records no asked rows for the requester, duplicates or people beyond the cap', function () {
    $requester = coOwnerUser(1, 'requester');
    $this->task->update(['started_by_user_id' => $requester->id]);
    $this->repository->update(['default_responsible_user_id' => $requester->id]);
    coOwnerUser(2, 'user2');

    $result = app(CoOwnerResolver::class)->resolve($this->repository->fresh(), $this->task->fresh(), ['@requester', '@user2', '@user2']);

    expect(coOwnerLogins($result))->toBe(['user2'])
        ->and($result['attempts'])->toBe([['entry' => '@user2', 'kind' => 'login', 'outcome' => 'asked']]);
});

it('records exactly three asked rows for a large team', function () {
    foreach (range(1, 5) as $id) {
        coOwnerUser($id, "member{$id}");
    }
    Http::fake(gitHubTokenFake() + [
        'api.github.com/orgs/acme/teams/core/members*' => Http::response(array_map(fn (int $id): array => ['login' => "member{$id}"], range(1, 5))),
    ]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@acme/core']);

    expect($result['users'])->toHaveCount(3)
        ->and(array_column($result['attempts'], 'outcome'))->toBe(['asked', 'asked', 'asked']);
});

it('matches logins and team members regardless of case', function () {
    coOwnerUser(1, 'MixedCase');
    coOwnerUser(2, 'TeamMate');
    Http::fake(gitHubTokenFake() + [
        'api.github.com/orgs/acme/teams/core/members*' => Http::response([['login' => 'teammate']]),
    ]);

    $result = app(CoOwnerResolver::class)->resolve($this->repository, $this->task, ['@mixedcase', '@acme/core']);

    expect(coOwnerLogins($result))->toBe(['MixedCase', 'TeamMate']);
});
