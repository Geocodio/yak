<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\CoOwner\CoOwnerGateStatus;
use App\Services\CoOwner\GitHubWriteAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $this->repository = Repository::factory()->create(['github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $GLOBALS['gateStatusAccess'] = [];
    $this->mock(GitHubWriteAccess::class, function ($mock) {
        $mock->shouldReceive('check')->andReturnUsing(fn ($repository, User $user): string => $GLOBALS['gateStatusAccess'][$user->github_login] ?? 'read');
        $mock->shouldReceive('hasWriteAccess')->andReturnUsing(fn ($repository, User $user): bool => ($GLOBALS['gateStatusAccess'][$user->github_login] ?? 'read') === 'write');
    });
});

function gateStatusUser(int $id, string $login, string $name, string $access = 'write'): User
{
    $GLOBALS['gateStatusAccess'][$login] = $access;

    return User::factory()->withGitHub($id, $login)->create(['name' => $name]);
}

function fakeGateStatus(string $mode, ?string $codeOwners, bool $withProfile = true): void
{
    $files = ['config.yml' => "version: 1\nco_owner_gate:\n  mode: {$mode}\n"];
    if ($withProfile) {
        $files['risk-profile.yml'] = riskProfileYaml();
    }
    fakeYakFiles($files);
    fakeRiskMapGitHub([], $codeOwners);
}

it('reports an enforced gate with resolvable owners and a fallback owner (frame 13a)', function () {
    gateStatusUser(1, 'mathias', 'Mathias Hansen');
    gateStatusUser(2, 'cory', 'Cory Mawhorter');
    $this->repository->update(['default_responsible_user_id' => gateStatusUser(3, 'dana', 'Dana Whitfield')->id]);
    fakeGateStatus('enforce', "* @mathias @cory\n/docs/ @dana @ghost\n");

    $status = app(CoOwnerGateStatus::class)->for($this->repository);

    expect($status['mode'])->toBe('enforce')
        ->and($status['source'])->toBe('file')
        ->and($status['profile'])->toBe(['path' => '.yak/risk-profile.yml', 'areaCount' => 2])
        ->and($status['owners'])->toBe(['resolvable' => ['Mathias Hansen', 'Cory Mawhorter', 'Dana Whitfield'], 'total' => 4, 'resolvedEntries' => 3, 'teamsSkipped' => []])
        ->and($status['fallbackOwner'])->toBe(['name' => 'Dana Whitfield', 'resolves' => true]);
});

it('reports an enforced gate where nobody can be asked (frame 13b)', function () {
    gateStatusUser(1, 'reader', 'Reader', 'read');
    fakeGateStatus('enforce', "* @reader @ghost @phantom\n");

    $status = app(CoOwnerGateStatus::class)->for($this->repository);

    expect($status['mode'])->toBe('enforce')
        ->and($status['owners'])->toBe(['resolvable' => [], 'total' => 3, 'resolvedEntries' => 0, 'teamsSkipped' => []])
        ->and($status['fallbackOwner'])->toBeNull();
});

it('reports skipped team entries (frame 13c)', function () {
    gateStatusUser(1, 'cory', 'Cory Mawhorter');
    $this->repository->update(['default_responsible_user_id' => gateStatusUser(3, 'dana', 'Dana Whitfield')->id]);
    Http::fake(['api.github.com/orgs/*' => Http::response([], 403)]);
    fakeGateStatus('enforce', "* @cory @acme/platform @acme/backend\n");

    $status = app(CoOwnerGateStatus::class)->for($this->repository);

    expect($status['owners'])->toBe(['resolvable' => ['Cory Mawhorter'], 'total' => 3, 'resolvedEntries' => 1, 'teamsSkipped' => ['@acme/platform', '@acme/backend']])
        ->and($status['fallbackOwner']['name'])->toBe('Dana Whitfield');
});

it('reports an off gate and still counts the owners that would resolve (frame 13d)', function () {
    gateStatusUser(1, 'mathias', 'Mathias Hansen');
    fakeGateStatus('off', "* @mathias\n");

    $status = app(CoOwnerGateStatus::class)->for($this->repository);

    expect($status['mode'])->toBe('off')
        ->and($status['profile']['areaCount'])->toBe(2)
        ->and($status['owners']['resolvable'])->toBe(['Mathias Hansen']);
});

it('serves the status from the cache for the same default-branch commit', function () {
    gateStatusUser(1, 'mathias', 'Mathias Hansen');
    fakeGateStatus('enforce', "* @mathias\n");

    app(CoOwnerGateStatus::class)->for($this->repository);
    $recorded = count(Http::recorded());
    app(CoOwnerGateStatus::class)->for($this->repository);

    expect(count(Http::recorded()))->toBe($recorded);
});

it('reads the fallback owner fresh even when the rest of the status is cached', function () {
    fakeGateStatus('enforce', "* @ghost\n");

    expect(app(CoOwnerGateStatus::class)->for($this->repository)['fallbackOwner'])->toBeNull();

    $this->repository->update(['default_responsible_user_id' => gateStatusUser(3, 'dana', 'Dana Whitfield')->id]);

    expect(app(CoOwnerGateStatus::class)->for($this->repository->fresh())['fallbackOwner'])->toBe(['name' => 'Dana Whitfield', 'resolves' => true]);
});

it('counts resolved entries, not users, against the total', function () {
    gateStatusUser(1, 'a', 'A');
    gateStatusUser(2, 'b', 'B');
    Http::fake(['api.github.com/orgs/acme/teams/core/members*' => Http::response([['login' => 'a'], ['login' => 'b']])]);
    fakeGateStatus('enforce', "* @acme/core\n");

    $owners = app(CoOwnerGateStatus::class)->for($this->repository)['owners'];

    expect($owners['resolvable'])->toHaveCount(2)->and($owners['total'])->toBe(1)->and($owners['resolvedEntries'])->toBe(1);
});

it('reads the owner type from the authenticated repository call', function () {
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/acme/api' => Http::response(['owner' => ['type' => 'Organization']])]);
    fakeGateStatus('enforce', "* @acme/platform\n");

    $status = app(CoOwnerGateStatus::class)->for($this->repository);

    expect($status['appSettingsUrl'])->toBe('https://github.com/organizations/acme/settings/installations/99999');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/users/acme'));
});
