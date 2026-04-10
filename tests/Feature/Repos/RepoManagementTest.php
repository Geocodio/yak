<?php

use App\Livewire\Repos\RepoForm;
use App\Livewire\Repos\RepoList;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
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

test('create repo with valid data', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'My New Repo')
        ->set('slug', 'my-new-repo')
        ->set('path', '/home/yak/repos/my-new-repo')
        ->set('default_branch', 'main')
        ->set('ci_system', 'github_actions')
        ->call('save')
        ->assertHasNoErrors();

    expect(Repository::where('slug', 'my-new-repo')->exists())->toBeTrue();
    $repo = Repository::where('slug', 'my-new-repo')->first();
    expect($repo->name)->toBe('My New Repo');
    expect($repo->path)->toBe('/home/yak/repos/my-new-repo');
});

test('create repo dispatches setup task', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'Setup Test')
        ->set('slug', 'setup-test')
        ->set('path', '/home/yak/repos/setup-test')
        ->call('save')
        ->assertHasNoErrors();

    $repo = Repository::where('slug', 'setup-test')->first();
    expect($repo->setup_task_id)->not->toBeNull();
    expect(YakTask::where('repo', 'setup-test')->where('source', 'dashboard')->exists())->toBeTrue();
});

test('slug auto-generates from name', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'My Cool Project')
        ->assertSet('slug', 'my-cool-project');
});

test('slug does not auto-generate on edit', function () {
    $repo = Repository::factory()->create(['slug' => 'original-slug', 'name' => 'Original']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('name', 'Updated Name')
        ->assertSet('slug', 'original-slug');
});

test('validation requires slug', function () {
    Livewire::test(RepoForm::class)
        ->set('name', 'Test')
        ->set('slug', '')
        ->set('path', '/home/test')
        ->call('save')
        ->assertHasErrors(['slug' => 'required']);
});

test('validation requires unique slug', function () {
    Repository::factory()->create(['slug' => 'taken-slug']);

    Livewire::test(RepoForm::class)
        ->set('name', 'Test')
        ->set('slug', 'taken-slug')
        ->set('path', '/home/test')
        ->call('save')
        ->assertHasErrors(['slug' => 'unique']);
});

test('validation allows same slug on edit', function () {
    $repo = Repository::factory()->create(['slug' => 'my-slug']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('slug', 'my-slug')
        ->set('name', 'Updated')
        ->set('path', '/home/test')
        ->call('save')
        ->assertHasNoErrors(['slug']);
});

test('validation requires absolute path', function () {
    Livewire::test(RepoForm::class)
        ->set('slug', 'test')
        ->set('name', 'Test')
        ->set('path', 'relative/path')
        ->call('save')
        ->assertHasErrors(['path']);
});

test('validation requires valid ci system', function () {
    Livewire::test(RepoForm::class)
        ->set('slug', 'test')
        ->set('name', 'Test')
        ->set('path', '/home/test')
        ->set('ci_system', 'invalid')
        ->call('save')
        ->assertHasErrors(['ci_system']);
});

test('default toggle clears previous default', function () {
    $existing = Repository::factory()->default()->create();

    Livewire::test(RepoForm::class)
        ->set('name', 'New Default')
        ->set('slug', 'new-default')
        ->set('path', '/home/yak/repos/new-default')
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
        'notes' => 'Some notes',
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
        ->assertSet('notes', 'Some notes')
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
