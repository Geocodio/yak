<?php

use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
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
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Prevent external API calls during tests.
    Cache::put('github-installation-repos', [], 300);
    Cache::put('sentry-projects', [], 300);
});

test('it requires authentication', function () {
    auth()->logout();

    $this->get(route('repos'))->assertRedirect(route('login'));
});

test('index renders repositories', function () {
    Repository::factory()->create(['name' => 'My Project', 'slug' => 'my-project']);

    $this->get(route('repos'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Repositories/Index')
            ->has('repositories', 1, fn (Assert $row) => $row
                ->where('slug', 'my-project')
                ->where('name', 'My Project')
                ->etc()));
});

test('index shows task counts and setup status', function () {
    Repository::factory()->create(['slug' => 'test-repo', 'setup_status' => 'ready']);
    YakTask::factory()->count(3)->create(['repo' => 'test-repo']);

    $this->get(route('repos'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('repositories', 1, fn (Assert $row) => $row
                ->where('slug', 'test-repo')
                ->where('setupStatus', 'ready')
                ->where('tasksTotal', 3)
                ->etc()));
});

test('index includes a 30-day review count on each repo', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'slug' => 'geocodio/api']);

    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(10)]);
    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(5)]);
    PrReview::factory()->create(['repo' => 'geocodio/api', 'submitted_at' => now()->subDays(40)]);

    $this->get(route('repos'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('repositories', 1, fn (Assert $row) => $row
                ->where('slug', 'geocodio/api')
                ->where('prReviews30d', 2)
                ->etc()));
});

test('create renders the form with github and sentry options', function () {
    $this->get(route('repos.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Repositories/Form')
            ->where('repository', null)
            ->has('options.ciSystems')
            ->has('options.defaultPathExcludes'));
});

test('create repo with valid data auto-generates slug and path', function () {
    $this->post(route('repos.store'), [
        'name' => 'My New Repo',
        'git_url' => 'https://github.com/acme/my-new-repo.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertRedirect();

    $repo = Repository::where('name', 'My New Repo')->first();
    expect($repo)->not->toBeNull();
    expect($repo->slug)->toBe('my-new-repo');
    expect($repo->path)->toBe('/home/yak/repos/my-new-repo');
    expect($repo->git_url)->toBe('https://github.com/acme/my-new-repo.git');
});

test('create repo generates unique slug when duplicate exists', function () {
    Repository::factory()->create(['slug' => 'my-project']);

    $this->post(route('repos.store'), [
        'name' => 'My Project',
        'git_url' => 'https://github.com/acme/my-project.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertRedirect();

    $repo = Repository::where('name', 'My Project')->latest('id')->first();
    expect($repo->slug)->toBe('my-project-1');
    expect($repo->path)->toBe('/home/yak/repos/my-project-1');
});

test('create repo dispatches setup task', function () {
    $this->post(route('repos.store'), [
        'name' => 'Setup Test',
        'git_url' => 'https://github.com/acme/setup-test.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertRedirect();

    $repo = Repository::where('slug', 'setup-test')->first();
    expect($repo->setup_task_id)->not->toBeNull();
    expect(YakTask::where('repo', 'setup-test')->where('source', 'dashboard')->exists())->toBeTrue();
});

test('adding a repo from github records its immutable repo id and full name', function () {
    $this->post(route('repos.store'), [
        'name' => 'cool-project',
        'git_url' => 'https://github.com/acme/cool-project.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
        'selected_github_repo' => 'acme/cool-project',
        'selected_github_repo_id' => 987654,
    ])->assertRedirect();

    $repo = Repository::where('slug', 'acme/cool-project')->first();

    expect($repo)->not->toBeNull()
        ->and($repo->github_repo_id)->toBe(987654)
        ->and($repo->github_full_name)->toBe('acme/cool-project');
});

test('validation requires name on create', function () {
    $this->post(route('repos.store'), [
        'name' => '',
        'git_url' => 'https://github.com/acme/test.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertSessionHasErrors(['name']);
});

test('validation requires git url on create', function () {
    $this->post(route('repos.store'), [
        'name' => 'Test',
        'git_url' => '',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ])->assertSessionHasErrors(['git_url']);
});

test('validation requires valid ci system', function () {
    $this->post(route('repos.store'), [
        'name' => 'Test',
        'git_url' => 'https://github.com/acme/test.git',
        'default_branch' => 'main',
        'ci_system' => 'invalid',
    ])->assertSessionHasErrors(['ci_system']);
});

test('default toggle clears previous default', function () {
    $existing = Repository::factory()->default()->create();

    $this->post(route('repos.store'), [
        'name' => 'New Default',
        'git_url' => 'https://github.com/acme/new-default.git',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
        'is_default' => true,
    ])->assertRedirect();

    expect($existing->refresh()->is_default)->toBeFalse();
    expect(Repository::where('slug', 'new-default')->first()->is_default)->toBeTrue();
});

test('edit renders the form pre-filled with repository data', function () {
    $repo = Repository::factory()->create([
        'slug' => 'edit-test',
        'name' => 'Edit Test',
        'path' => '/home/yak/repos/edit-test',
        'default_branch' => 'develop',
        'ci_system' => 'drone',
        'sentry_project' => 'my-sentry',
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->get(route('repos.edit', $repo))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Repositories/Form')
            ->where('repository.slug', 'edit-test')
            ->where('repository.name', 'Edit Test')
            ->where('repository.path', '/home/yak/repos/edit-test')
            ->where('repository.defaultBranch', 'develop')
            ->where('repository.ciSystem', 'drone')
            ->where('repository.sentryProject', 'my-sentry')
            ->where('repository.isActive', true)
            ->where('repository.isDefault', true));
});

test('edit surfaces the github name when it has diverged from the slug', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_full_name' => 'geocodio/infrastructure',
    ]);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->where('repository.githubFullName', 'geocodio/infrastructure')
            ->where('repository.githubNameDiverged', true));
});

test('edit exposes setup history limited to 10 most recent setup tasks for the repository', function () {
    $repo = Repository::factory()->create(['slug' => 'history-test']);

    YakTask::factory()->create([
        'repo' => 'history-test',
        'mode' => TaskMode::Setup,
        'external_id' => 'setup-aaa',
    ]);
    YakTask::factory()->create([
        'repo' => 'history-test',
        'mode' => TaskMode::Fix,
        'external_id' => 'fix-bbb',
    ]);
    YakTask::factory()->create([
        'repo' => 'other-repo',
        'mode' => TaskMode::Setup,
        'external_id' => 'setup-ccc',
    ]);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->has('setupHistory', 1, fn (Assert $row) => $row
                ->where('id', 'setup-aaa')
                ->etc()));
});

test('editing a repo persists agent_instructions', function () {
    $repo = Repository::factory()->create(['agent_instructions' => null]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'agent_instructions' => "- Don't run local tests, CI covers it.\n- Use pnpm.",
    ]))->assertRedirect();

    expect($repo->fresh()->agent_instructions)->toContain("Don't run local tests");
});

test('editing a repo clears agent_instructions when emptied', function () {
    $repo = Repository::factory()->create(['agent_instructions' => 'legacy note']);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'agent_instructions' => '',
    ]))->assertRedirect();

    expect($repo->fresh()->agent_instructions)->toBeNull();
});

test('edit form validates slug uniqueness', function () {
    Repository::factory()->create(['slug' => 'taken-slug']);
    $repo = Repository::factory()->create(['slug' => 'my-slug']);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'slug' => 'taken-slug',
    ]))->assertSessionHasErrors(['slug']);
});

test('edit form allows same slug', function () {
    $repo = Repository::factory()->create(['slug' => 'my-slug']);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'slug' => 'my-slug',
        'name' => 'Updated',
    ]))->assertSessionDoesntHaveErrors(['slug']);
});

test('default toggle on edit clears other defaults', function () {
    $existing = Repository::factory()->default()->create();
    $editing = Repository::factory()->create();

    $this->patch(route('repos.update', $editing), array_merge(baseRepoPayload($editing), [
        'is_default' => true,
    ]))->assertRedirect();

    expect($existing->refresh()->is_default)->toBeFalse();
    expect($editing->refresh()->is_default)->toBeTrue();
});

test('a public site url loads and saves', function () {
    $repo = Repository::factory()->create(['public_site_url' => null]);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page->where('repository.publicSiteUrl', null));

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'public_site_url' => 'https://www.example.com',
    ]))->assertRedirect();

    expect($repo->fresh()->public_site_url)->toBe('https://www.example.com');
});

test('public site url stores null when the field is cleared', function () {
    $repo = Repository::factory()->create(['public_site_url' => 'https://www.example.com']);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'public_site_url' => '',
    ]))->assertRedirect();

    expect($repo->fresh()->public_site_url)->toBeNull();
});

test('public site url rejects a value that is not a url', function () {
    $repo = Repository::factory()->create();

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'public_site_url' => 'not a url',
    ]))->assertSessionHasErrors(['public_site_url']);
});

test('canDelete is false when the repository has tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'has-tasks']);
    YakTask::factory()->create(['repo' => 'has-tasks']);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canDelete', false)
            ->whereNot('deleteBlockedReason', null));
});

test('delete blocked when repository has tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'has-tasks']);
    YakTask::factory()->create(['repo' => 'has-tasks']);

    $this->delete(route('repos.destroy', $repo))->assertRedirect();

    expect(Repository::where('slug', 'has-tasks')->exists())->toBeTrue();
});

test('delete allowed with zero tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'no-tasks']);

    $this->delete(route('repos.destroy', $repo))
        ->assertRedirect(route('repos'));

    expect(Repository::where('slug', 'no-tasks')->exists())->toBeFalse();
});

test('github search returns the json shape and hides already-added repos', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Repository::factory()->create(['slug' => 'acme/already-added']);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'acme/already-added',
            'name' => 'already-added',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/already-added.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
            'private' => false,
            'language' => 'PHP',
        ],
        [
            'full_name' => 'acme/fresh',
            'name' => 'fresh',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/fresh.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
            'private' => false,
            'language' => 'PHP',
        ],
    ], 300);

    $response = $this->getJson(route('repos.github-search'))
        ->assertOk()
        ->assertJsonStructure(['repos' => [['fullName', 'private', 'language', 'pushedAt', 'defaultBranch']]]);

    $names = collect($response->json('repos'))->pluck('fullName')->all();
    expect($names)->toBe(['acme/fresh']);
});

test('github search filters results by query', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'acme/website',
            'name' => 'website',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/website.git',
            'pushed_at' => null,
        ],
        [
            'full_name' => 'acme/api-server',
            'name' => 'api-server',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/api-server.git',
            'pushed_at' => null,
        ],
    ], 300);

    $response = $this->getJson(route('repos.github-search', ['q' => 'web']))->assertOk();

    $names = collect($response->json('repos'))->pluck('fullName')->all();
    expect($names)->toBe(['acme/website']);
});

test('a repo already tracked under an old slug is not offered again after a github rename', function () {
    config(['yak.channels.github.installation_id' => 12345]);

    Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_full_name' => 'geocodio/infrastructure',
    ]);

    Cache::put('github-installation-repos', [
        [
            'id' => 555,
            'full_name' => 'geocodio/infrastructure',
            'name' => 'infrastructure',
            'description' => null,
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/geocodio/infrastructure.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
        ],
    ], 300);

    $response = $this->getJson(route('repos.github-search'))->assertOk();

    expect($response->json('repos'))->toBe([]);
});

test('github detect returns the detected ci system', function () {
    config(['yak.channels.github.installation_id' => 12345]);

    $github = mock(AppService::class);
    $github->shouldReceive('detectCiSystem')->with(12345, 'acme/api')->andReturn('drone');
    app()->instance(AppService::class, $github);

    $this->getJson(route('repos.github-detect', ['full_name' => 'acme/api']))
        ->assertOk()
        ->assertJson(['ciSystem' => 'drone']);
});

test('github detect returns none when github actions workflows are found', function () {
    config(['yak.channels.github.installation_id' => 12345]);

    $github = mock(AppService::class);
    $github->shouldReceive('detectCiSystem')->with(12345, 'acme/api')->andReturn('github_actions');
    app()->instance(AppService::class, $github);

    $this->getJson(route('repos.github-detect', ['full_name' => 'acme/api']))
        ->assertOk()
        ->assertJson(['ciSystem' => 'github_actions']);
});

test('github detect returns none without an installation configured', function () {
    config(['yak.channels.github.installation_id' => null]);

    $this->getJson(route('repos.github-detect', ['full_name' => 'acme/api']))
        ->assertOk()
        ->assertJson(['ciSystem' => 'none']);
});

test('adding a path exclude pattern persists', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'pr_review_path_excludes' => null]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'path_excludes' => ['custom/**'],
    ]))->assertRedirect();

    expect($repo->fresh()->pr_review_path_excludes)->toBe(['custom/**']);
});

test('removing a path exclude pattern persists', function () {
    $repo = Repository::factory()->create([
        'pr_review_enabled' => true,
        'pr_review_path_excludes' => ['a/**', 'b/**'],
    ]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'path_excludes' => ['b/**'],
    ]))->assertRedirect();

    expect($repo->fresh()->pr_review_path_excludes)->toBe(['b/**']);
});

test('resetting path excludes to defaults persists null', function () {
    $repo = Repository::factory()->create([
        'pr_review_enabled' => true,
        'pr_review_path_excludes' => ['only-this/**'],
    ]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'apply_to_open_prs' => false,
        'path_excludes' => null,
    ]))->assertRedirect();

    expect($repo->fresh()->pr_review_path_excludes)->toBeNull();
});

test('rejects an invalid glob pattern in path excludes', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => true, 'pr_review_path_excludes' => null]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'path_excludes' => ['bad; rm -rf'],
    ]))->assertSessionHasErrors(['path_excludes.0']);
});

test('pr_review_enabled toggle persists', function () {
    $repo = Repository::factory()->create(['pr_review_enabled' => false]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'apply_to_open_prs' => false,
    ]))->assertRedirect();

    expect($repo->fresh()->pr_review_enabled)->toBeTrue();
});

test('deployments_enabled toggle persists', function () {
    $repo = Repository::factory()->create(['deployments_enabled' => false]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'deployments_enabled' => true,
    ]))->assertRedirect();

    expect($repo->fresh()->deployments_enabled)->toBeTrue();
});

test('enabling pr review with apply_to_open_prs enqueues review tasks', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    config(['yak.channels.github.app_bot_login' => 'yak-bot[bot]']);

    Bus::fake();
    $repo = Repository::factory()->create(['pr_review_enabled' => false, 'slug' => 'geocodio/api']);

    $github = mock(AppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak-bot[bot]');
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 1, 'html_url' => 'u1', 'title' => '', 'body' => '', 'draft' => false, 'user' => ['login' => 'maria'], 'head' => ['ref' => 'h', 'sha' => 's1'], 'base' => ['ref' => 'main', 'sha' => 'b1']],
    ]);
    app()->instance(AppService::class, $github);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'pr_review_enabled' => true,
        'apply_to_open_prs' => true,
    ]))->assertRedirect();

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

test('setup history is limited to the 10 most recent setup tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'limit-test']);

    YakTask::factory()->count(12)->sequence(fn ($sequence) => [
        'external_id' => 'setup-limit-' . $sequence->index,
        'created_at' => now()->subMinutes(12 - $sequence->index),
    ])->create([
        'repo' => 'limit-test',
        'mode' => TaskMode::Setup,
    ]);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->has('setupHistory', 10)
            ->where('setupHistory.0.id', 'setup-limit-11')
            ->where('setupHistory.9.id', 'setup-limit-2'));
});

test('edit exposes a docs link for the repositories guide', function () {
    $repo = Repository::factory()->create();

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->where('docsUrl', fn (string $url) => str_contains($url, 'repositories')));
});

test('saving the repository form persists manifest fields in the same request', function () {
    $repo = Repository::factory()->create(['deployments_enabled' => true, 'preview_manifest' => null]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'manifest' => [
            'port' => 4000,
            'health_probe_path' => '/healthz',
            'cold_start' => 'docker compose up -d',
            'checkout_refresh' => 'docker compose build && composer install',
            'wake_timeout_seconds' => 90,
        ],
    ]))->assertRedirect();

    $manifest = $repo->fresh()->preview_manifest;
    expect($manifest['port'])->toBe(4000);
    expect($manifest['health_probe_path'])->toBe('/healthz');
    expect($manifest['cold_start'])->toBe('docker compose up -d');
    expect($manifest['checkout_refresh'])->toBe('docker compose build && composer install');
    expect($manifest['wake_timeout_seconds'])->toBe(90);
});

test('saving the repository form without a manifest payload leaves the existing manifest untouched', function () {
    $repo = Repository::factory()->create([
        'deployments_enabled' => false,
        'preview_manifest' => ['port' => 5000, 'health_probe_path' => '/', 'cold_start' => 'x', 'checkout_refresh' => 'y', 'wake_timeout_seconds' => 60],
    ]);

    $this->patch(route('repos.update', $repo), baseRepoPayload($repo))->assertRedirect();

    expect($repo->fresh()->preview_manifest['port'])->toBe(5000);
});

test('manifest port validation errors surface on the merged save', function () {
    $repo = Repository::factory()->create(['deployments_enabled' => true]);

    $this->patch(route('repos.update', $repo), array_merge(baseRepoPayload($repo), [
        'manifest' => [
            'port' => 0,
            'health_probe_path' => '/',
            'cold_start' => '',
            'checkout_refresh' => '',
            'wake_timeout_seconds' => 60,
        ],
    ]))->assertSessionHasErrors(['manifest.port']);
});

/**
 * Minimal valid payload for an edit-mode save, so each test only needs to
 * override the fields it cares about.
 *
 * @return array<string, mixed>
 */
function baseRepoPayload(Repository $repo): array
{
    return [
        'slug' => $repo->slug,
        'name' => $repo->name,
        'git_url' => $repo->git_url ?? 'https://github.com/acme/repo.git',
        'path' => $repo->path,
        'default_branch' => $repo->default_branch,
        'ci_system' => $repo->ci_system,
    ];
}
