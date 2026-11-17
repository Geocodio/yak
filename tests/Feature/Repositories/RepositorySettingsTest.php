<?php

use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    Cache::put('sentry-projects', [], 300);
});

test('each section url renders its component with the shared header', function (string $section, string $component) {
    $repo = Repository::factory()->create(['slug' => 'acme/site', 'approval_level' => 3, 'approval_mode' => 'enforce']);

    $url = $section === 'general' ? route('repos.edit', $repo) : route('repos.settings.show', ['repository' => $repo, 'section' => $section]);

    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('header.slug', 'acme/site')
        ->where('header.approval.level', 3)
        ->where('header.approval.mode', 'enforce')
        ->has('header.sections', 7)
        ->missing('yakConfig'));
})->with([
    ['general', 'Repositories/Settings/General'],
    ['review', 'Repositories/Settings/Review'],
    ['previews', 'Repositories/Settings/Previews'],
    ['sandbox', 'Repositories/Settings/Sandbox'],
    ['accounts', 'Repositories/Settings/Accounts'],
    ['danger', 'Repositories/Settings/Danger'],
]);

test('the approvals page receives the header', function () {
    $repo = Repository::factory()->create();

    $this->get(route('repos.approvals.show', $repo))->assertInertia(fn (Assert $page) => $page
        ->where('header.approval.level', 1)
        ->where('header.approval.mode', 'off'));
});

test('an unknown section returns 404', function () {
    $repo = Repository::factory()->create();

    $this->get("/repos/{$repo->slug}/settings/nope")->assertNotFound();
    $this->get("/repos/{$repo->slug}/settings/approvals")->assertNotFound();
});

test('saving one field leaves every other section untouched', function () {
    $repo = Repository::factory()->create([
        'name' => 'Keep Me',
        'public_site_url' => null,
        'preview_manifest' => ['port' => 5000, 'health_probe_path' => '/', 'cold_start' => '', 'checkout_refresh' => '', 'wake_timeout_seconds' => 60],
        'pr_review_path_excludes' => ['vendor/**'],
        'pr_review_policy' => ['profile_max_age_days' => 45],
        'pr_review_enabled' => true,
        'deployments_enabled' => true,
        'is_active' => true,
        'description' => 'Hello',
    ]);

    $this->patch(route('repos.update', $repo), ['public_site_url' => 'https://a.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $fresh = $repo->fresh();
    expect($fresh->public_site_url)->toBe('https://a.test')
        ->and($fresh->name)->toBe('Keep Me')
        ->and($fresh->description)->toBe('Hello')
        ->and($fresh->preview_manifest['port'])->toBe(5000)
        ->and($fresh->pr_review_path_excludes)->toBe(['vendor/**'])
        ->and($fresh->reviewPolicy()['profile_max_age_days'])->toBe(45)
        ->and($fresh->pr_review_enabled)->toBeTrue()
        ->and($fresh->deployments_enabled)->toBeTrue()
        ->and($fresh->is_active)->toBeTrue();
});

test('saving a single field redirects back with a saved message', function () {
    $repo = Repository::factory()->create();

    $this->from(route('repos.settings.show', ['repository' => $repo, 'section' => 'review']))
        ->patch(route('repos.update', $repo), ['path_excludes' => ['a/**']])
        ->assertRedirect(route('repos.settings.show', ['repository' => $repo, 'section' => 'review']))
        ->assertSessionHas('success', 'Saved.');
});

test('a blank optional text field clears the column', function () {
    $repo = Repository::factory()->create(['description' => 'Hello', 'sentry_project' => 'x']);

    $this->patch(route('repos.update', $repo), ['description' => '', 'sentry_project' => null])->assertSessionHasNoErrors();

    expect($repo->fresh()->description)->toBeNull()->and($repo->fresh()->sentry_project)->toBeNull();
});

test('turning pr review on without apply_to_open_prs enqueues nothing', function () {
    Bus::fake();
    $repo = Repository::factory()->create(['pr_review_enabled' => false]);
    $github = mock(AppService::class);
    $github->shouldNotReceive('listOpenPullRequests');
    app()->instance(AppService::class, $github);

    $this->patch(route('repos.update', $repo), ['pr_review_enabled' => true, 'apply_to_open_prs' => false])->assertSessionHasNoErrors();

    expect($repo->fresh()->pr_review_enabled)->toBeTrue()
        ->and(YakTask::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('turning pr review on with apply_to_open_prs reviews the open pull requests', function () {
    config(['yak.channels.github.installation_id' => 12345, 'yak.channels.github.app_bot_login' => 'yak-bot[bot]']);
    Bus::fake();
    $repo = Repository::factory()->create(['pr_review_enabled' => false, 'slug' => 'geocodio/api']);
    $github = mock(AppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak-bot[bot]');
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 1, 'html_url' => 'u1', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
    ]);
    app()->instance(AppService::class, $github);

    $this->patch(route('repos.update', $repo), ['pr_review_enabled' => true, 'apply_to_open_prs' => true])->assertSessionHasNoErrors();

    expect(YakTask::count())->toBe(1);
});

test('store still requires the create fields', function () {
    $this->post(route('repos.store'), [])->assertSessionHasErrors(['name', 'git_url', 'default_branch', 'ci_system']);
});

test('index rows carry the approval level and mode', function () {
    Repository::factory()->create(['slug' => 'a/b', 'approval_level' => 4, 'approval_mode' => 'shadow']);

    $this->get(route('repos'))->assertInertia(fn (Assert $page) => $page
        ->has('repositories.0', fn (Assert $row) => $row
            ->where('approvalLevel', 4)
            ->where('approvalMode', 'shadow')
            ->etc()));
});

test('general counts the days of shadow reviews at a level above 1', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/site', 'approval_level' => 2, 'approval_mode' => 'shadow']);
    PrReview::factory()->create(['repo' => 'acme/site', 'risk_assessment' => ['mode' => 'shadow', 'level' => 2], 'created_at' => now()->subDays(20)]);
    PrReview::factory()->create(['repo' => 'acme/site', 'risk_assessment' => ['mode' => 'off', 'level' => 1], 'created_at' => now()->subDays(40)]);

    $this->get(route('repos.edit', $repo))->assertInertia(fn (Assert $page) => $page->where('shadowDays', 20));
});

test('general reports the open concerns pull request and survives GitHub failing', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/site']);
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(['number' => 9431, 'html_url' => 'https://github.com/acme/site/pull/9431']);
    $this->instance(AppService::class, $github);

    $this->get(route('repos.edit', $repo))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('concerns-pull-request', fn (Assert $reload) => $reload
            ->where('concernsPullRequest.number', 9431)));

    $failing = Mockery::mock(AppService::class);
    $failing->shouldReceive('findOpenPullRequestForBranch')->andThrow(new RuntimeException('down'));
    $this->instance(AppService::class, $failing);

    $this->get(route('repos.edit', $repo))->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps('concerns-pull-request', fn (Assert $reload) => $reload
            ->where('concernsPullRequest', null)));
});
