<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RiskProfileMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Load the deferred risk profile prop and run assertions on it.
 */
function assertRiskProfile(TestCase $testCase, Repository $repository, Closure $assertions): void
{
    $testCase->actingAs(User::factory()->create())->get(route('repos.risk-profile.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Repositories/RiskProfile')
            ->missing('riskProfile')
            ->loadDeferredProps('risk-profile', $assertions));
}

function riskMapRequestCount(): int
{
    return Http::recorded()->count();
}

it('defers the profile and renders the map from the head tree', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php', '.yak/risk-profile.yml'], "app/Http/** @mhansen\n");

    $this->actingAs(User::factory()->create())->get(route('repos.risk-profile.show', $repository))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Repositories/RiskProfile')
            ->where('repository.slug', $repository->slug)
            ->missing('riskProfile')
            ->loadDeferredProps('risk-profile', fn (AssertableInertia $reload) => $reload
                ->where('riskProfile.state', 'profile')
                ->where('riskProfile.source', 'file')
                ->where('riskProfile.map.available', true)
                ->where('riskProfile.map.totalFiles', 3)
                ->where('riskProfile.map.coveredFiles', 1)
                ->where('riskProfile.profile.riskCounts.high', 2)
                ->where('riskProfile.profile.areas.0.files', 1)
                ->where('riskProfile.profile.areas.0.owners', ['@mhansen'])
                ->where('riskProfile.profile.areas.1.matchesNoFile', true)
                ->where('riskProfile.profile.unknowns.0', 'Does launcher/ touch production credentials?')
                ->where('riskProfile.file.changedIn.pullRequest.number', 4801)));
});

it('serves the second load from cache without calling GitHub', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload->where('riskProfile.map.available', true));
    $sentAfterFirstLoad = riskMapRequestCount();
    expect($sentAfterFirstLoad)->toBeGreaterThan(0);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.map.available', true)
        ->where('riskProfile.map.totalFiles', 1)
        ->where('riskProfile.file.changedIn.pullRequest.number', 4801));

    expect(riskMapRequestCount())->toBe($sentAfterFirstLoad);
});

it('shows the notice when GitHub truncates the tree', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], isTruncated: true);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'profile')
        ->where('riskProfile.map.available', false)
        ->where('riskProfile.map.reason', 'truncated')
        ->where('riskProfile.profile.areas.0.files', null)
        ->where('riskProfile.profile.areas.0.paths.0.files', null)
        ->where('riskProfile.profile.areas.0.owners', [])
        ->where('riskProfile.profile.areas.1.matchesNoFile', false));
});

it('shows the notice when matching goes over the budget', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php']);
    app()->instance(RiskProfileMatcher::class, new RiskProfileMatcher(maxComparisons: 1));

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.map.available', false)
        ->where('riskProfile.map.reason', 'too-large')
        ->where('riskProfile.profile.areas.0.files', null));
});

it('shows the profile without a map when GitHub is down and nothing is cached', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], treeStatus: 503);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'profile')
        ->where('riskProfile.map.available', false)
        ->where('riskProfile.map.reason', 'unreachable')
        ->where('riskProfile.profile.areas.0.name', 'API middleware and HTTP kernel'));

    expect(Cache::get('risk-map:' . $repository->id . ':' . str_repeat('a', 40) . ':' . hash('sha256', riskProfileYaml())))->toBeNull();
});

it('shows a cached map as frame 7 without calling GitHub', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload->where('riskProfile.map.available', true));

    // GitHub going down afterwards must not turn the cached map into a notice.
    Http::fake(['*' => Http::response([], 503)]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'profile')
        ->where('riskProfile.readState', 'read')
        ->where('riskProfile.map.available', true)
        ->where('riskProfile.profile.areas.0.files', 1));
});

it('falls back to the approved database profile', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/api']);
    fakeYakFiles([]);
    $profile = [
        'schema_version' => 1, 'repo' => 'acme/api', 'source_sha' => str_repeat('c', 40),
        'areas' => [['name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => [], 'risk' => 'critical', 'rationale' => 'Money.', 'evidence' => ['Invoice.php']]],
        'unknowns' => [],
    ];
    $profile['version'] = hash('sha256', json_encode([$profile['schema_version'], $profile['repo'], $profile['source_sha'], $profile['areas'], $profile['unknowns']]));
    RiskProfile::factory()->approved()->create(['repo' => 'acme/api', 'version' => $profile['version'], 'profile' => $profile]);
    fakeRiskMapGitHub(['app/Billing/Invoice.php']);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'profile')
        ->where('riskProfile.source', 'database')
        ->where('riskProfile.file', null)
        ->where('riskProfile.profile.riskCounts.critical', 1)
        ->where('riskProfile.profile.areas.0.files', 1));
});

it('reports a never-valid file', function () {
    $repository = Repository::factory()->create();
    $file = new ConfigFile('risk-profile.yml', null, "version: 9\n", null, 'risk-profile.yml: version must be 1', str_repeat('d', 40), null);
    bindConfigSnapshot(new ConfigSnapshot('read', str_repeat('a', 40), CarbonImmutable::now(), null, ['risk-profile.yml' => $file]));
    fakeNoRiskProfilePullRequest();

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'never-valid')
        ->where('riskProfile.source', null)
        ->where('riskProfile.file.error', 'risk-profile.yml: version must be 1')
        ->where('riskProfile.file.validCommitSha', null)
        ->where('riskProfile.profile', null)
        ->where('riskProfile.map', null)
        ->where('riskProfile.generation', null));
});

it('reports the unavailable state without a GitHub App', function () {
    $repository = Repository::factory()->create();

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'unavailable')
        ->where('riskProfile.readState', 'unavailable')
        ->where('riskProfile.profile', null)
        ->where('riskProfile.generation', null)
        ->where('riskProfile.map', null));
});

it('reports a running generation task', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    $task = YakTask::factory()->create(['repo' => $repository->slug, 'external_id' => 'risk-profile-x', 'status' => TaskStatus::Running]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'empty')
        ->where('riskProfile.generation.status', 'running')
        ->where('riskProfile.generation.task.id', $task->id)
        ->where('riskProfile.generation.task.url', route('tasks.show', $task))
        ->where('riskProfile.generation.pullRequest', null)
        ->where('riskProfile.profile', null));
});

it('reports a profile PR waiting for merge with its counts', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/pulls*' => Http::response([['number' => 4812, 'title' => 'Add risk profile', 'html_url' => 'https://github.com/o/r/pull/4812']]),
        'api.github.com/repos/*/contents/.yak/risk-profile.yml*' => Http::response(riskProfileYaml()),
        '*' => Http::response([], 500),
    ]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'empty')
        ->where('riskProfile.generation.status', 'waiting')
        ->where('riskProfile.generation.pullRequest.number', 4812)
        ->where('riskProfile.generation.pullRequest.areas', 2)
        ->where('riskProfile.generation.pullRequest.unknowns', 1));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'contents/.yak/risk-profile.yml') && $request['ref'] === 'yak/risk-profile');
});

it('leaves the counts null when the draft file cannot be read', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/pulls*' => Http::response([['number' => 4812, 'title' => 'Add risk profile', 'html_url' => 'https://github.com/o/r/pull/4812']]),
        '*' => Http::response([], 500),
    ]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.generation.status', 'waiting')
        ->where('riskProfile.generation.pullRequest.areas', null)
        ->where('riskProfile.generation.pullRequest.unknowns', null));
});

it('reports a failed generation with the last error line', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    YakTask::factory()->create([
        'repo' => $repository->slug, 'external_id' => 'risk-profile-y', 'status' => TaskStatus::Failed,
        'error_log' => "a\nAgent stopped: turn limit reached\n\n",
    ]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'empty')
        ->where('riskProfile.generation.status', 'failed')
        ->where('riskProfile.generation.task.error', 'Agent stopped: turn limit reached'));
});

it('is empty with no file, no draft and no task', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    YakTask::factory()->create(['repo' => $repository->slug, 'external_id' => 'other-task', 'status' => TaskStatus::Running]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'empty')
        ->where('riskProfile.generation', null)
        ->where('riskProfile.profile', null)
        ->where('riskProfile.map', null));
});

it('never writes to GitHub', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload->where('riskProfile.state', 'profile'));

    Http::assertNotSent(fn ($request) => ! in_array($request->method(), ['GET'], true) && ! str_contains($request->url(), '/access_tokens'));
});

it('requires authentication', function () {
    $this->get(route('repos.risk-profile.show', Repository::factory()->create()))->assertRedirect();
});

it('encodes the default branch in the file links', function () {
    $repository = Repository::factory()->create(['git_url' => 'https://github.com/o/r.git', 'default_branch' => 'release/v1#x']);
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.file.blobUrl', 'https://github.com/o/r/blob/release/v1%23x/.yak/risk-profile.yml')
        ->where('riskProfile.file.editUrl', 'https://github.com/o/r/edit/release/v1%23x/.yak/risk-profile.yml'));
});

it('reports an unreachable map for a database profile when GitHub was never read', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/api']);
    $profile = [
        'schema_version' => 1, 'repo' => 'acme/api', 'source_sha' => str_repeat('c', 40),
        'areas' => [['name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => [], 'risk' => 'critical', 'rationale' => 'Money.', 'evidence' => ['Invoice.php']]],
        'unknowns' => [],
    ];
    $profile['version'] = hash('sha256', json_encode([$profile['schema_version'], $profile['repo'], $profile['source_sha'], $profile['areas'], $profile['unknowns']]));
    RiskProfile::factory()->approved()->create(['repo' => 'acme/api', 'version' => $profile['version'], 'profile' => $profile]);
    bindConfigSnapshot(new ConfigSnapshot('unreachable', null, null, 'GitHub returned 503', []));

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.state', 'profile')
        ->where('riskProfile.source', 'database')
        ->where('riskProfile.commitSha', null)
        ->where('riskProfile.map.available', false)
        ->where('riskProfile.map.reason', 'unreachable')
        ->where('riskProfile.profile.areas.0.files', null));
});

it('does not cache the map when the last change of the profile file could not be looked up', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    Http::fake(['api.github.com/repos/*/commits?path*' => Http::sequence()->push([], 503)->push([['sha' => str_repeat('b', 40), 'commit' => ['author' => ['name' => 'Ada', 'date' => '2026-10-09T10:00:00Z']]]])]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.map.available', true)
        ->where('riskProfile.file.changedIn', null));

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload
        ->where('riskProfile.map.available', true)
        ->where('riskProfile.file.changedIn.sha', str_repeat('b', 40))
        ->where('riskProfile.file.changedIn.authorName', 'Ada'));
});

it('ignores an array change parameter', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    $this->actingAs(User::factory()->create())
        ->get(route('repos.risk-profile.show', $repository) . '?change[]=x')
        ->assertOk();
});

it('reports a failed tree fetch and degrades the map', function () {
    Exceptions::fake();
    configureFakeGitHubApp();
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*/git/trees/*' => fn () => throw new ConnectionException('down')]);

    assertRiskProfile($this, $repository, fn (AssertableInertia $reload) => $reload->where('riskProfile.state', 'profile'));

    Exceptions::assertReported(ConnectionException::class);
});
