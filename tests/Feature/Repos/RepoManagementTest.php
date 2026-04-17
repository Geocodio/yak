<?php

use App\Enums\TaskMode;
use App\Livewire\Repos\RepoForm;
use App\Livewire\Repos\RepoList;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Prevent external API calls during tests
    Cache::put('github-installation-repos', [], 300);
    Cache::put('sentry-projects', [], 300);
});

test('repo list renders with repositories', function () {
    $repo = Repository::factory()->create(['name' => 'My Project', 'slug' => 'my-project']);

    Livewire::test(RepoList::class)
        ->assertSee('My Project')
        ->assertSee('my-project');
});

test('repo list shows task counts', function () {
    $repo = Repository::factory()->create(['slug' => 'test-repo']);
    YakTask::factory()->count(3)->create(['repo' => 'test-repo']);

    Livewire::test(RepoList::class)
        ->assertSee('test-repo');
});

test('repo list shows setup status badge', function () {
    Repository::factory()->create(['setup_status' => 'ready']);
    Repository::factory()->create(['setup_status' => 'pending']);

    Livewire::test(RepoList::class)
        ->assertSee('Ready')
        ->assertSee('Pending');
});

test('repo list shows active and inactive badges', function () {
    Repository::factory()->create(['name' => 'Active Repo', 'is_active' => true]);
    Repository::factory()->create(['name' => 'Inactive Repo', 'is_active' => false]);

    Livewire::test(RepoList::class)
        ->assertSee('Active')
        ->assertSee('Inactive');
});

test('repo list shows default star', function () {
    Repository::factory()->default()->create(['name' => 'Default Repo']);
    Repository::factory()->create(['name' => 'Regular Repo']);

    Livewire::test(RepoList::class)
        ->assertSee('Default Repo')
        ->assertSee('Regular Repo');
});

test('create repo with valid data auto-generates slug and path', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'My New Repo')
        ->set('git_url', 'https://github.com/acme/my-new-repo.git')
        ->set('default_branch', 'main')
        ->set('ci_system', 'github_actions')
        ->call('save')
        ->assertHasNoErrors();

    $repo = Repository::where('name', 'My New Repo')->first();
    expect($repo)->not->toBeNull();
    expect($repo->slug)->toBe('my-new-repo');
    expect($repo->path)->toBe('/home/yak/repos/my-new-repo');
    expect($repo->git_url)->toBe('https://github.com/acme/my-new-repo.git');
});

test('editing a repo persists agent_instructions', function () {
    $repo = Repository::factory()->create([
        'agent_instructions' => null,
    ]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('agent_instructions', "- Don't run local tests, CI covers it.\n- Use pnpm.")
        ->call('save')
        ->assertHasNoErrors();

    expect($repo->fresh()->agent_instructions)->toContain("Don't run local tests");
});

test('editing a repo clears agent_instructions when emptied', function () {
    $repo = Repository::factory()->create([
        'agent_instructions' => 'legacy note',
    ]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('agent_instructions', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($repo->fresh()->agent_instructions)->toBeNull();
});

test('create repo generates unique slug when duplicate exists', function () {
    Repository::factory()->create(['slug' => 'my-project']);

    Livewire::test(RepoForm::class)
        ->set('name', 'My Project')
        ->set('git_url', 'https://github.com/acme/my-project.git')
        ->set('default_branch', 'main')
        ->set('ci_system', 'github_actions')
        ->call('save')
        ->assertHasNoErrors();

    $repo = Repository::where('name', 'My Project')->latest('id')->first();
    expect($repo->slug)->toBe('my-project-1');
    expect($repo->path)->toBe('/home/yak/repos/my-project-1');
});

test('create repo dispatches setup task', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'Setup Test')
        ->set('git_url', 'https://github.com/acme/setup-test.git')
        ->set('default_branch', 'main')
        ->call('save')
        ->assertHasNoErrors();

    $repo = Repository::where('slug', 'setup-test')->first();
    expect($repo->setup_task_id)->not->toBeNull();
    expect(YakTask::where('repo', 'setup-test')->where('source', 'dashboard')->exists())->toBeTrue();
});

test('select github repo fills form fields', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'acme/cool-project',
            'name' => 'cool-project',
            'description' => 'A cool project that does cool things.',
            'default_branch' => 'develop',
            'clone_url' => 'https://github.com/acme/cool-project.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
        ],
    ], 300);

    Livewire::test(RepoForm::class)
        ->call('selectGitHubRepo', 'acme/cool-project')
        ->assertSet('selected_github_repo', 'acme/cool-project')
        ->assertSet('name', 'cool-project')
        ->assertSet('description', 'A cool project that does cool things.')
        ->assertSet('git_url', 'https://github.com/acme/cool-project.git')
        ->assertSet('default_branch', 'develop');
});

test('selecting a github repo pre-selects a matching Sentry project', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'geocodio/geocodio-website',
            'name' => 'geocodio-website',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/geocodio/geocodio-website.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
        ],
    ], 300);
    Cache::put('sentry-projects', [
        ['slug' => 'api', 'name' => 'API'],
        ['slug' => 'geocodio-website', 'name' => 'Geocodio Website'],
        ['slug' => 'dashboard', 'name' => 'Dashboard'],
    ], 300);

    Livewire::test(RepoForm::class)
        ->call('selectGitHubRepo', 'geocodio/geocodio-website')
        ->assertSet('sentry_project', 'geocodio-website');
});

test('selecting a github repo does not pre-select Sentry when nothing matches closely', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'acme/unique-tool',
            'name' => 'unique-tool',
            'default_branch' => 'main',
            'clone_url' => 'https://github.com/acme/unique-tool.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
        ],
    ], 300);
    Cache::put('sentry-projects', [
        ['slug' => 'api', 'name' => 'API'],
        ['slug' => 'dashboard', 'name' => 'Dashboard'],
    ], 300);

    Livewire::test(RepoForm::class)
        ->call('selectGitHubRepo', 'acme/unique-tool')
        ->assertSet('sentry_project', '');
});

test('clear selected repo resets form fields', function () {
    config(['yak.channels.github.installation_id' => 12345]);
    Cache::put('github-installation-repos', [
        [
            'full_name' => 'acme/cool-project',
            'name' => 'cool-project',
            'default_branch' => 'develop',
            'clone_url' => 'https://github.com/acme/cool-project.git',
            'pushed_at' => null,
        ],
    ], 300);

    Livewire::test(RepoForm::class)
        ->call('selectGitHubRepo', 'acme/cool-project')
        ->call('clearSelectedRepo')
        ->assertSet('selected_github_repo', '')
        ->assertSet('name', '')
        ->assertSet('git_url', '')
        ->assertSet('default_branch', 'main');
});

test('github repo picker hides repos that are already added', function () {
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

    Livewire::test(RepoForm::class)
        ->assertSee('fresh')
        ->assertDontSee('already-added');
});

test('github repo search filters results', function () {
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

    Livewire::test(RepoForm::class)
        ->set('github_search', 'web')
        ->assertSee('website')
        ->assertDontSee('api-server');
});

test('validation requires name on create', function () {
    Livewire::test(RepoForm::class)
        ->set('name', '')
        ->set('git_url', 'https://github.com/acme/test.git')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

test('validation requires git url on create', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'Test')
        ->set('git_url', '')
        ->call('save')
        ->assertHasErrors(['git_url' => 'required']);
});

test('validation requires valid ci system', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'Test')
        ->set('git_url', 'https://github.com/acme/test.git')
        ->set('ci_system', 'invalid')
        ->call('save')
        ->assertHasErrors(['ci_system']);
});

test('edit form validates slug uniqueness', function () {
    Repository::factory()->create(['slug' => 'taken-slug']);
    $repo = Repository::factory()->create(['slug' => 'my-slug']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('slug', 'taken-slug')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);
});

test('edit form allows same slug', function () {
    $repo = Repository::factory()->create(['slug' => 'my-slug']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('slug', 'my-slug')
        ->set('name', 'Updated')
        ->call('save')
        ->assertHasNoErrors(['slug']);
});

test('default toggle clears previous default', function () {
    $existing = Repository::factory()->default()->create();

    Livewire::test(RepoForm::class)
        ->set('name', 'New Default')
        ->set('git_url', 'https://github.com/acme/new-default.git')
        ->set('default_branch', 'main')
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($existing->refresh()->is_default)->toBeFalse();
    expect(Repository::where('slug', 'new-default')->first()->is_default)->toBeTrue();
});

test('default toggle on edit clears other defaults', function () {
    $existing = Repository::factory()->default()->create();
    $editing = Repository::factory()->create();

    Livewire::test(RepoForm::class, ['repository' => $editing])
        ->set('is_default', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($existing->refresh()->is_default)->toBeFalse();
    expect($editing->refresh()->is_default)->toBeTrue();
});

test('edit form pre-fills repository data', function () {
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

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->assertSet('slug', 'edit-test')
        ->assertSet('name', 'Edit Test')
        ->assertSet('path', '/home/yak/repos/edit-test')
        ->assertSet('default_branch', 'develop')
        ->assertSet('ci_system', 'drone')
        ->assertSet('sentry_project', 'my-sentry')
        ->assertSet('is_active', true)
        ->assertSet('is_default', true);
});

test('deactivate repository', function () {
    $repo = Repository::factory()->create(['is_active' => true]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('toggleActive');

    expect($repo->refresh()->is_active)->toBeFalse();
});

test('activate repository', function () {
    $repo = Repository::factory()->inactive()->create();

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('toggleActive');

    expect($repo->refresh()->is_active)->toBeTrue();
});

test('delete blocked when repository has tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'has-tasks']);
    YakTask::factory()->create(['repo' => 'has-tasks']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('delete');

    expect(Repository::where('slug', 'has-tasks')->exists())->toBeTrue();
});

test('delete allowed with zero tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'no-tasks']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('delete');

    expect(Repository::where('slug', 'no-tasks')->exists())->toBeFalse();
});

test('rerun setup dispatches new task', function () {
    $repo = Repository::factory()->create(['slug' => 'rerun-test']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('rerunSetup');

    expect($repo->refresh()->setup_task_id)->not->toBeNull();
    expect(YakTask::where('repo', 'rerun-test')->exists())->toBeTrue();
});

test('rerun setup redirects to the new task', function () {
    $repo = Repository::factory()->create(['slug' => 'redirect-test']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->call('rerunSetup')
        ->assertRedirect(route('tasks.show', YakTask::where('repo', 'redirect-test')->first()));
});

test('setup history shows recent setup tasks for the repository', function () {
    $repo = Repository::factory()->create(['slug' => 'history-test']);

    YakTask::factory()->create([
        'repo' => 'history-test',
        'mode' => TaskMode::Setup,
        'external_id' => 'setup-aaa',
        'description' => 'Setup repository: history-test',
    ]);
    YakTask::factory()->create([
        'repo' => 'history-test',
        'mode' => TaskMode::Fix,
        'external_id' => 'fix-bbb',
        'description' => 'Unrelated fix',
    ]);
    YakTask::factory()->create([
        'repo' => 'other-repo',
        'mode' => TaskMode::Setup,
        'external_id' => 'setup-ccc',
    ]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->assertSee('Setup History')
        ->assertSee('setup-aaa')
        ->assertDontSee('fix-bbb')
        ->assertDontSee('setup-ccc');
});

test('setup history limits to 10 most recent tasks', function () {
    $repo = Repository::factory()->create(['slug' => 'limit-test']);

    YakTask::factory()->count(12)->sequence(fn ($sequence) => [
        'external_id' => 'setup-limit-' . $sequence->index,
        'created_at' => now()->subMinutes(12 - $sequence->index),
    ])->create([
        'repo' => 'limit-test',
        'mode' => TaskMode::Setup,
    ]);

    $html = Livewire::test(RepoForm::class, ['repository' => $repo])->html();

    // Newest 10 visible (indexes 2..11), oldest 2 hidden (indexes 0..1)
    expect($html)->toContain('setup-limit-11')
        ->toContain('setup-limit-2')
        ->not->toContain('>setup-limit-1<')
        ->not->toContain('>setup-limit-0<');
});

test('open on github link renders on edit page when git url present', function () {
    $repo = Repository::factory()->create([
        'slug' => 'github-link-test',
        'git_url' => 'https://github.com/acme/my-repo.git',
    ]);

    $component = Livewire::test(RepoForm::class, ['repository' => $repo]);

    expect($component->html())->toContain('https://github.com/acme/my-repo');
});

test('sentry projects load as dropdown options', function () {
    Cache::put('sentry-projects', [
        ['slug' => 'my-app', 'name' => 'My App'],
        ['slug' => 'api', 'name' => 'API Service'],
    ], 300);

    Livewire::test(RepoForm::class)
        ->assertSet('sentry_projects', [
            ['slug' => 'my-app', 'name' => 'My App'],
            ['slug' => 'api', 'name' => 'API Service'],
        ]);
});
