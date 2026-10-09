<?php

use App\Actions\OpenConfigMigrationPullRequest;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\User;
use App\Services\RepositoryConfig;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function migrationBody(bool $riskProfileUnapproved, array $checksLostAppPin, ?string $riskProfileDate = '2026-09-21'): string
{
    return view('pull-requests.config-migration', [
        'repository' => Repository::factory()->make(['name' => 'geocodio']),
        'files' => ['.yak/config.yml' => 'Description, CI system', '.yak/risk-profile.yml' => 'Risk profile draft'],
        'riskProfileUnapproved' => $riskProfileUnapproved,
        'riskProfileDate' => $riskProfileDate,
        'checksLostAppPin' => $checksLostAppPin,
    ])->render();
}

it('renders the C5 sections', function () {
    $body = migrationBody(false, []);

    expect($body)->toContain('## Summary')
        ->toContain('| `.yak/config.yml` | Description, CI system |')
        ->toContain('## After merging')
        ->toContain('`yak / config`')
        ->not->toContain('never approved')
        ->not->toContain('match by name');
});

it('warns about the risk profile only when it was never approved', function () {
    expect(migrationBody(true, []))->toContain('The risk profile was never approved')->toContain('2026-09-21');
});

it('names the checks that lose their app pin only when there are some', function () {
    expect(migrationBody(false, ['tests', 'phpstan']))->toContain('Required checks match by name')->toContain('`tests` and `phpstan`');
});

it('refuses to migrate a repository that already has .yak/ files', function () {
    $repository = Repository::factory()->create(['slug' => 'api']);
    fakeYakFiles(['config.yml' => "version: 1\n"]);

    app(OpenConfigMigrationPullRequest::class)->handle($repository);
})->throws(RuntimeException::class, 'api already has .yak/ files');

it('flashes the opened pull request', function () {
    $repository = Repository::factory()->create();
    $this->mock(OpenConfigMigrationPullRequest::class)->shouldReceive('handle')->once()
        ->andReturn(['number' => 12, 'url' => 'https://github.com/acme/api/pull/12', 'created' => true]);

    $this->actingAs(User::factory()->create())->post(route('repos.config.migrate', $repository))
        ->assertRedirect()
        ->assertSessionHas('success', 'Opened #12 https://github.com/acme/api/pull/12');
});

it('flashes an error when the action throws', function () {
    $repository = Repository::factory()->create();
    $this->mock(OpenConfigMigrationPullRequest::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('GitHub App is not configured'));

    $this->actingAs(User::factory()->create())->post(route('repos.config.migrate', $repository))
        ->assertRedirect()
        ->assertSessionHas('error', 'GitHub App is not configured');
});

it('refuses to migrate and writes nothing when .yak/ could not be read', function () {
    Http::fake();
    $repository = Repository::factory()->create(['slug' => 'api']);
    fakeUnreachableYakRead(['api']);

    expect(fn () => app(OpenConfigMigrationPullRequest::class)->handle($repository))
        ->toThrow(RuntimeException::class, 'Could not read .yak/ for api from GitHub');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'git/blobs'));
});

it('escapes the repository name and check names', function () {
    $body = view('pull-requests.config-migration', [
        'repository' => Repository::factory()->make(['name' => 'a <!-- @org/team']),
        'files' => ['.yak/config.yml' => 'x'],
        'riskProfileUnapproved' => false,
        'riskProfileDate' => null,
        'checksLostAppPin' => ['te`st'],
    ])->render();

    expect($body)->toContain('**a \<\!\-\- @' . "\u{200B}" . 'org/team**')
        ->toContain("`te'st`")
        ->not->toContain('<!--');
});

function migrationRepositoryWithApprovedProfile(): Repository
{
    $repository = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $profile = ['schema_version' => 1, 'repo' => 'acme/api', 'source_sha' => str_repeat('a', 40),
        'areas' => [['name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => [], 'risk' => 'high',
            'rationale' => 'Handles money.', 'evidence' => ['app/Billing/Invoice.php']]],
        'unknowns' => []];
    $profile['version'] = hash('sha256', json_encode([
        $profile['schema_version'], $profile['repo'], $profile['source_sha'], $profile['areas'], $profile['unknowns'],
    ]));
    RiskProfile::create(['repo' => 'acme/api', 'version' => $profile['version'], 'profile' => $profile,
        'approved_by' => 'Ada', 'approved_at' => now()]);

    return $repository;
}

it('labels an approved risk profile as approved in the pull request body', function () {
    $repository = migrationRepositoryWithApprovedProfile();
    fakeUnreachableYakRead([]);
    fakeGithubConfigPullRequestApi();

    app(OpenConfigMigrationPullRequest::class)->handle($repository);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/pulls')
        && str_contains($request['body'], '| `.yak/risk-profile.yml` | Approved risk profile |')
        && ! str_contains($request['body'], 'Risk profile draft from'));
});

it('reads the default branch head again instead of trusting a cached one', function () {
    $repository = migrationRepositoryWithApprovedProfile();
    app()->forgetInstance(RepositoryConfig::class);
    Cache::put(RepositoryConfig::headCacheKey($repository), str_repeat('9', 40), now()->addMinutes(5));
    fakeGithubConfigPullRequestApi(overrides: [
        'api.github.com/repos/acme/api/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    app(OpenConfigMigrationPullRequest::class)->handle($repository);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/branches/main'));
});
