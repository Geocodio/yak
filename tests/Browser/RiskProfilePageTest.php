<?php

use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
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
        ->assertNoJavascriptErrors();
});
