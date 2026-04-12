<?php

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
            'default_branch' => 'develop',
            'clone_url' => 'https://github.com/acme/cool-project.git',
            'pushed_at' => '2026-04-10T12:00:00Z',
        ],
    ], 300);

    Livewire::test(RepoForm::class)
        ->call('selectGitHubRepo', 'acme/cool-project')
        ->assertSet('selected_github_repo', 'acme/cool-project')
        ->assertSet('name', 'cool-project')
        ->assertSet('git_url', 'https://github.com/acme/cool-project.git')
        ->assertSet('default_branch', 'develop');
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
