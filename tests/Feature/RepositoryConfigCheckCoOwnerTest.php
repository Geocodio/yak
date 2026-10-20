<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\RepositoryConfigCheck;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturnUsing(fn ($repository, User $user): string => $user->github_login === 'alice' ? 'write' : 'read');
        $mock->shouldReceive('hasWriteAccess')->andReturnUsing(fn ($repository, User $user): bool => $user->github_login === 'alice');
    });
});

/** @param  list<string>  $changed */
function fakeCoOwnerCheck(array $changed, ?string $codeOwners, string $mode = 'off', string $headRepository = 'acme/api', int $codeOwnersStatus = 200): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/api/pulls/7/files*' => Http::response(array_map(fn (string $name): array => ['filename' => $name], $changed)),
        'api.github.com/repos/acme/api/pulls/7' => Http::response(['head' => ['repo' => ['full_name' => $headRepository]]]),
        'api.github.com/repos/acme/api/check-runs' => Http::response(['id' => 5], 201),
        'api.github.com/repos/acme/api/contents/.yak/config.yml*' => Http::response("version: 1\nco_owner_gate:\n  mode: {$mode}\n"),
        'api.github.com/repos/acme/api/contents/.github/CODEOWNERS*' => $codeOwnersStatus !== 200 ? Http::response(['message' => 'Error'], $codeOwnersStatus) : ($codeOwners === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($codeOwners)),
        'api.github.com/repos/acme/api/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
}

it('warns about unresolvable CODEOWNERS entries without failing the check', function () {
    User::factory()->withGitHub(1, 'alice')->create();
    fakeCoOwnerCheck(['.yak/config.yml'], "* @alice @ghost\n");

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40));

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])
        ->toContain('CODEOWNERS entry `@ghost` does not resolve to a linked Yak user with write access.')
        ->not->toContain('`@alice` does not resolve');
});

it('warns when enforce has nobody to ask', function () {
    fakeCoOwnerCheck(['.yak/config.yml'], "* @ghost\n", 'enforce');

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40));

    expect($payload['conclusion'])->toBe('success')
        ->and($payload['output']['summary'])
        ->toContain('co_owner_gate.mode is enforce, but no CODEOWNERS owner or fallback owner resolves, so held changes would wait for anyone with write access.');
});

it('does not warn about an empty owner list when a fallback owner resolves', function () {
    $this->repository->update(['default_responsible_user_id' => User::factory()->withGitHub(1, 'alice')->create()->id]);
    fakeCoOwnerCheck(['.yak/config.yml'], null, 'enforce');

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40));

    expect($payload['output']['summary'])->not->toContain('co_owner_gate.mode is enforce, but');
});

it('runs for a pull request that touches only a CODEOWNERS file', function () {
    fakeCoOwnerCheck(['.github/CODEOWNERS'], "* @ghost\n");

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40));

    expect($payload)->not->toBeNull()
        ->and($payload['output']['summary'])->toContain('`@ghost` does not resolve');
});

it('still posts nothing for a pull request that touches neither', function () {
    fakeCoOwnerCheck(['app/Foo.php'], "* @ghost\n");

    expect(app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40)))->toBeNull();
});

it('gives a fork pull request no per-entry warning but keeps the aggregate one', function () {
    fakeCoOwnerCheck(['.yak/config.yml'], "* @ghost\n", 'enforce', 'attacker/api');

    $summary = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40))['output']['summary'];

    expect($summary)->not->toContain('`@ghost`')
        ->and($summary)->toContain('co_owner_gate.mode is enforce, but');
});

it('never warns about an email entry', function () {
    fakeCoOwnerCheck(['.yak/config.yml'], "* secret@example.com @ghost\n");

    $summary = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40))['output']['summary'];

    expect($summary)->not->toContain('secret@example.com')->and($summary)->toContain('`@ghost`');
});

it('skips the warnings and still succeeds when CODEOWNERS cannot be fetched', function () {
    fakeCoOwnerCheck(['.yak/config.yml'], null, 'off', 'acme/api', 500);

    $payload = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40));

    expect($payload['conclusion'])->toBe('success')->and($payload['output']['summary'])->not->toContain('CODEOWNERS entry');
});

it('never echoes an entry with a backtick or pipe into the summary', function () {
    fakeCoOwnerCheck(['.yak/config.yml'], "* a|b`c@example.com @ghost\n");

    $summary = app(RepositoryConfigCheck::class)->run($this->repository, 7, str_repeat('a', 40))['output']['summary'];

    expect($summary)->not->toContain('a|b')->and($summary)->not->toContain('b`c')->and($summary)->toContain('`@ghost`');
});
