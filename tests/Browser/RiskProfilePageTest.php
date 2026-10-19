<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\User;
use App\Models\YakTask;
use App\Services\RepositoryConfigParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

it('explains an empty profile and offers generation', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('No risk profile yet')
        ->assertSee('Generate risk profile')
        ->assertVisible('[data-testid="risk-profile-empty"]')
        ->assertNoJavascriptErrors();
});

it('shows a running generation task', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    YakTask::factory()->create(['repo' => $repository->slug, 'external_id' => 'risk-profile-a', 'status' => TaskStatus::Running, 'started_at' => now()->subMinutes(4)]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('Generating the risk profile')
        ->assertVisible('[data-testid="risk-profile-generating"]')
        ->assertNoJavascriptErrors();
});

it('shows a failed generation with its error', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    fakeNoRiskProfilePullRequest();
    YakTask::factory()->create([
        'repo' => $repository->slug,
        'external_id' => 'risk-profile-b',
        'status' => TaskStatus::Failed,
        'error_log' => "starting\nAgent stopped: turn limit reached",
    ]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('Generating the risk profile failed')
        ->assertSee('Agent stopped: turn limit reached')
        ->assertSee('Retry generation')
        ->assertVisible('[data-testid="risk-profile-failed"]')
        ->assertNoJavascriptErrors();
});

it('shows a profile PR waiting for merge', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles([]);
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/pulls*' => Http::response([['number' => 4812, 'title' => 'Add risk profile', 'html_url' => 'https://github.com/o/r/pull/4812']]),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('The profile PR is waiting for merge')
        ->assertSee('#4812')
        ->assertSee('Add risk profile')
        ->assertVisible('[data-testid="risk-profile-waiting"]')
        ->assertNoJavascriptErrors();
});

it('shows a file that has never been valid', function () {
    $repository = Repository::factory()->create();
    $sha = str_repeat('d', 40);
    $file = new ConfigFile('risk-profile.yml', null, "version: 9\n", null, "version: required\nareas[3].paths: must not be empty", $sha, null);
    bindConfigSnapshot(new ConfigSnapshot('read', $sha, CarbonImmutable::now(), null, ['risk-profile.yml' => $file]));
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('has never been valid')
        ->assertSee('It was added in dddddd')
        ->assertSee('areas[3].paths: must not be empty')
        ->assertSee('Generate a replacement')
        ->assertVisible('[data-testid="risk-profile-never-valid"]')
        ->assertNoJavascriptErrors();
});

it('explains that the page needs the GitHub App', function () {
    $repository = Repository::factory()->create();
    bindConfigSnapshot(new ConfigSnapshot('unavailable', null, null, null, []));
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('this installation has none configured')
        ->assertVisible('[data-testid="risk-profile-unavailable"]')
        ->assertNoJavascriptErrors();
});

it('renders the profile view for a valid profile', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], null);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-profile-view"]')
        ->assertSee('Read-only')
        ->assertSee('From .yak/risk-profile.yml on')
        ->assertNoJavascriptErrors();
});

it('names the default branch for a profile stored in Yak', function () {
    $repository = Repository::factory()->create(['slug' => 'acme/api', 'default_branch' => 'trunk']);
    fakeYakFiles([]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], null);
    $profile = [
        'schema_version' => 1, 'repo' => 'acme/api', 'source_sha' => str_repeat('c', 40),
        'areas' => [['name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => [], 'risk' => 'critical', 'rationale' => 'Money.', 'evidence' => ['Invoice.php']]],
        'unknowns' => [],
    ];
    $profile['version'] = hash('sha256', json_encode([$profile['schema_version'], $profile['repo'], $profile['source_sha'], $profile['areas'], $profile['unknowns']]));
    RiskProfile::factory()->approved()->create(['repo' => 'acme/api', 'version' => $profile['version'], 'profile' => $profile]);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('From the approved profile stored in Yak. No .yak/risk-profile.yml on trunk yet.')
        ->assertNoJavascriptErrors();
});

/**
 * @return array<string, mixed>
 */
function riskProfileFileData(): array
{
    return (new RepositoryConfigParser)->parse('risk-profile.yml', riskProfileYaml())['data'];
}

it('shows tiles and the open-questions banner for a valid profile', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('2 in the profile')
        ->assertSee('50% of files')
        ->assertSee('1 in an area, 1 in none')
        ->assertSee('Always held')
        ->assertSee('1 open question in the profile')
        ->assertSee('1 area matches no file on')
        ->assertDontSee('Held in the last 30 days')
        ->assertVisible('[data-testid="risk-map"]')
        ->assertNoJavascriptErrors();
});

it('switches to the areas tab and keeps it in the URL hash', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map"]')
        ->click('Jump to questions')
        ->assertVisible('[data-testid="risk-areas"]')
        ->assertScript('location.hash', '#areas')
        ->assertNoJavascriptErrors();

    visit(route('repos.risk-profile.show', $repository) . '#areas')
        ->assertVisible('[data-testid="risk-areas"]')
        ->assertNoJavascriptErrors();
});

it('shows the invalid-file banner with the last valid version', function () {
    $repository = Repository::factory()->create();
    $validSha = 'a1b2c3d' . str_repeat('0', 33);
    $file = new ConfigFile('risk-profile.yml', riskProfileFileData(), riskProfileYaml(), $validSha, 'areas[1].risk: "extreme" must be one of critical, high, medium, low, unknown', '9f8e7d6' . str_repeat('0', 33), ['number' => 4803, 'title' => 'Rate the launcher as low', 'url' => 'https://github.com/o/r/pull/4803']);
    bindConfigSnapshot(new ConfigSnapshot('read', $validSha, CarbonImmutable::now(), null, ['risk-profile.yml' => $file]));
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('is invalid')
        ->assertSee('Introduced in 9f8e7d6')
        ->assertSee('#4803 “Rate the launcher as low”')
        ->assertSee('Yak keeps using the version from a1b2c3d until the file is fixed.')
        ->assertSee('areas[1].risk: "extreme" must be one of')
        ->assertSee('Showing the version from a1b2c3d on')
        ->assertSee('Open the file')
        ->assertNoJavascriptErrors();
});

it('leaves out the pull request clause when the invalid commit has none', function () {
    $repository = Repository::factory()->create();
    $validSha = 'a1b2c3d' . str_repeat('0', 33);
    $file = new ConfigFile('risk-profile.yml', riskProfileFileData(), riskProfileYaml(), $validSha, 'version: required', '9f8e7d6' . str_repeat('0', 33), null);
    bindConfigSnapshot(new ConfigSnapshot('read', $validSha, CarbonImmutable::now(), null, ['risk-profile.yml' => $file]));
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('Introduced in 9f8e7d6. Yak keeps using the version from a1b2c3d')
        ->assertNoJavascriptErrors();
});

it('shows the unreachable banner', function () {
    $repository = Repository::factory()->create();
    $sha = 'a1b2c3d' . str_repeat('0', 33);
    $file = new ConfigFile('risk-profile.yml', riskProfileFileData(), riskProfileYaml(), $sha, null, null, null);
    bindConfigSnapshot(new ConfigSnapshot('unreachable', $sha, CarbonImmutable::now()->subHours(3), '503 Service Unavailable', ['risk-profile.yml' => $file]));
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('Could not read .yak/ from GitHub')
        ->assertSee('Using the version from a1b2c3d, read 3h ago. GitHub said: 503 Service Unavailable.')
        ->assertSee('Showing the version from a1b2c3d on')
        ->assertSee('Try again')
        ->assertNoJavascriptErrors();
});

it('shows a dash for coverage when the map is unavailable', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], null, true);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('Needs the full file list')
        ->assertDontSee('% of files')
        ->assertNoJavascriptErrors();
});
