<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Livewire\Tasks\TaskList;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it renders tasks table', function () {
    $task = YakTask::factory()->running()->create([
        'description' => 'Fix something important',
        'repo' => 'my-repo',
        'source' => 'slack',
        'external_id' => 'SLACK-001',
    ]);

    Livewire::test(TaskList::class)
        ->assertSee('Fix something important')
        ->assertSee('my-repo')
        ->assertSee('Slack')
        ->assertSee('SLACK-001');
});

test('it filters by status', function () {
    YakTask::factory()->running()->create(['description' => 'Running task']);
    YakTask::factory()->success()->create(['description' => 'Success task']);

    Livewire::test(TaskList::class)
        ->assertSee('Running task')
        ->assertSee('Success task')
        ->set('status', 'running')
        ->assertSee('Running task')
        ->assertDontSee('Success task');
});

test('it filters by source', function () {
    YakTask::factory()->create(['source' => 'slack', 'description' => 'Slack task']);
    YakTask::factory()->create(['source' => 'sentry', 'description' => 'Sentry task']);

    Livewire::test(TaskList::class)
        ->assertSee('Slack task')
        ->assertSee('Sentry task')
        ->set('source', 'slack')
        ->assertSee('Slack task')
        ->assertDontSee('Sentry task');
});

test('it filters by repo', function () {
    YakTask::factory()->create(['repo' => 'api', 'description' => 'API task']);
    YakTask::factory()->create(['repo' => 'web', 'description' => 'Web task']);

    Livewire::test(TaskList::class)
        ->assertSee('API task')
        ->assertSee('Web task')
        ->set('repo', 'api')
        ->assertSee('API task')
        ->assertDontSee('Web task');
});

test('it paginates tasks', function () {
    YakTask::factory()->count(51)->create();

    Livewire::test(TaskList::class)
        ->assertSee('Next');
});

test('it uses polling', function () {
    Livewire::test(TaskList::class)
        ->assertSeeHtml('wire:poll.15s');
});

test('it shows status badges with correct labels', function () {
    foreach (TaskStatus::cases() as $status) {
        YakTask::factory()->create(['status' => $status]);
    }

    $component = Livewire::test(TaskList::class);

    foreach (TaskStatus::cases() as $status) {
        $component->assertSee(str_replace('_', ' ', $status->value));
    }
});

test('it shows an open pr badge linking to the pr', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/123',
        'description' => 'Task with PR',
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeHtml('href="https://github.com/org/repo/pull/123"')
        ->assertSeeHtml('data-testid="pr-state-' . $task->id . '"')
        ->assertSee('Open');
});

test('it shows merged and closed pr states', function () {
    $merged = YakTask::factory()->merged()->create();
    $closed = YakTask::factory()->closedWithoutMerge()->create();

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="pr-state-' . $merged->id . '"')
        ->assertSeeHtml('data-testid="pr-state-' . $closed->id . '"')
        ->assertSee('Merged')
        ->assertSee('Closed');
});

test('repo column links to the pr when the task has one', function () {
    Repository::factory()->create(['slug' => 'pr-project']);
    YakTask::factory()->success()->create([
        'repo' => 'pr-project',
        'pr_url' => 'https://github.com/org/pr-project/pull/7',
    ]);

    $html = Livewire::test(TaskList::class)->html();

    expect($html)
        ->toContain('href="https://github.com/org/pr-project/pull/7"')
        ->not->toContain('href="' . route('repos.edit', 'pr-project') . '"');
});

test('status is rendered as a dot with a visually hidden label', function () {
    $task = YakTask::factory()->failed()->create();

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="status-dot-' . $task->id . '"')
        ->assertSeeHtml(TaskList::statusDotClasses(TaskStatus::Failed))
        ->assertSee('failed');
});

test('it shows the relative creation time with absolute timestamps in the tooltip', function () {
    $task = YakTask::factory()->success()->create([
        'created_at' => now()->subHours(3),
        'duration_ms' => 180000,
    ]);

    Livewire::test(TaskList::class)
        ->assertSee('3h ago')
        ->assertSee('Created ' . $task->created_at->format('M j, Y H:i'))
        ->assertSee('Ran for 3m');
});

test('it formats age as a short relative time', function () {
    expect(TaskList::formatAge(null))->toBe('—')
        ->and(TaskList::formatAge(now()->subMinutes(5)))->toBe('5m ago')
        ->and(TaskList::formatAge(now()->subDays(2)))->toBe('2d ago');
});

test('it shows dash when no pr', function () {
    YakTask::factory()->create(['pr_url' => null]);

    Livewire::test(TaskList::class)
        ->assertSee('—');
});

test('it sorts by creation date descending', function () {
    YakTask::factory()->create([
        'description' => 'Older task',
        'created_at' => now()->subDay(),
    ]);
    YakTask::factory()->create([
        'description' => 'Newer task',
        'created_at' => now(),
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeInOrder(['Newer task', 'Older task']);
});

test('it is accessible at /tasks route', function () {
    $response = $this->get('/tasks');

    $response->assertOk();
    $response->assertSeeLivewire(TaskList::class);
});

test('it requires authentication', function () {
    auth()->logout();

    $this->get('/tasks')->assertRedirect(route('login'));
});

test('it formats duration correctly', function () {
    expect(TaskList::formatDuration(null))->toBe('—')
        ->and(TaskList::formatDuration(0))->toBe('—')
        ->and(TaskList::formatDuration(30000))->toBe('1m')
        ->and(TaskList::formatDuration(180000))->toBe('3m')
        ->and(TaskList::formatDuration(3600000))->toBe('1h')
        ->and(TaskList::formatDuration(5400000))->toBe('1h 30m');
});

test('it resets page when filter changes', function () {
    YakTask::factory()->count(51)->create(['source' => 'slack']);

    Livewire::test(TaskList::class)
        ->call('gotoPage', 2)
        ->set('status', 'pending')
        ->assertNotSet('page', 2);
});

test('default tab shows only fix and research tasks', function () {
    YakTask::factory()->create(['mode' => TaskMode::Fix, 'description' => 'Fix task A']);
    YakTask::factory()->create(['mode' => TaskMode::Research, 'description' => 'Research task B']);
    YakTask::factory()->create(['mode' => TaskMode::Setup, 'description' => 'Setup task C']);

    Livewire::test(TaskList::class)
        ->assertSee('Fix task A')
        ->assertSee('Research task B')
        ->assertDontSee('Setup task C');
});

test('setup tab shows only setup tasks', function () {
    YakTask::factory()->create(['mode' => TaskMode::Fix, 'description' => 'Fix task A']);
    YakTask::factory()->create(['mode' => TaskMode::Setup, 'description' => 'Setup task C']);

    Livewire::test(TaskList::class)
        ->set('tab', 'setup')
        ->assertSee('Setup task C')
        ->assertDontSee('Fix task A');
});

test('tab counts reflect mode scopes', function () {
    YakTask::factory()->count(3)->create(['mode' => TaskMode::Fix]);
    YakTask::factory()->count(2)->create(['mode' => TaskMode::Setup]);

    Livewire::test(TaskList::class)
        ->assertSet('tab', 'tasks')
        ->assertSeeInOrder(['Tasks', '3', 'Setup', '2']);
});

test('changing tab resets page', function () {
    YakTask::factory()->count(51)->create(['mode' => TaskMode::Fix]);

    Livewire::test(TaskList::class)
        ->call('gotoPage', 2)
        ->set('tab', 'setup')
        ->assertNotSet('page', 2);
});

test('source filter is hidden on setup tab', function () {
    YakTask::factory()->create(['mode' => TaskMode::Setup]);

    $component = Livewire::test(TaskList::class)
        ->set('tab', 'setup');

    expect($component->html())->not->toContain('aria-label="Filter by source"');
});

test('source filter is shown on tasks tab', function () {
    YakTask::factory()->create(['mode' => TaskMode::Fix]);

    $component = Livewire::test(TaskList::class);

    expect($component->html())->toContain('aria-label="Filter by source"');
});

test('repo column links to repository edit page when repository exists', function () {
    Repository::factory()->create(['slug' => 'my-project']);
    YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'my-project',
        'description' => 'Linked task',
    ]);

    $component = Livewire::test(TaskList::class);

    expect($component->html())
        ->toContain('href="' . route('repos.edit', 'my-project') . '"');
});

test('repo column renders plain text when no repository record exists', function () {
    YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'ghost-repo',
        'description' => 'Orphan task',
    ]);

    $component = Livewire::test(TaskList::class);

    expect($component->html())
        ->toContain('ghost-repo')
        ->not->toContain('href="' . url('/repos/ghost-repo/edit') . '"');
});

/*
|--------------------------------------------------------------------------
| Getting started card
|--------------------------------------------------------------------------
*/

test('shows setup card to a first-time user with no repos or tasks', function () {
    User::query()->delete();
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    Livewire::test(TaskList::class)
        ->assertSet('showSetupCard', true)
        ->assertSeeHtml('data-testid="setup-card"');
});

test('hides setup card once the user has dismissed it', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $this->actingAs($user);

    Livewire::test(TaskList::class)
        ->assertSet('showSetupCard', false)
        ->assertDontSeeHtml('data-testid="setup-card"');
});

test('hides setup card once repos and tasks both exist', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    Repository::factory()->create();
    YakTask::factory()->create();

    Livewire::test(TaskList::class)
        ->assertSet('showSetupCard', false);
});

test('dismissSetupCard records the timestamp and hides the card', function () {
    User::query()->delete();
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    Livewire::test(TaskList::class)
        ->assertSet('showSetupCard', true)
        ->call('dismissSetupCard')
        ->assertSet('showSetupCard', false);

    expect($user->fresh()->has_seen_setup_card_at)->not->toBeNull();
});

test('setup checklist reflects real state', function () {
    User::query()->delete();
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    $component = Livewire::test(TaskList::class);
    $checklist = $component->get('setupChecklist');

    expect($checklist)->toBeArray()->toHaveCount(3);
    expect($checklist[0]['done'])->toBeFalse();
    expect($checklist[1]['done'])->toBeFalse();
    expect($checklist[2]['done'])->toBeFalse();

    // Now add a repo — first item should flip to done.
    Repository::factory()->create();
    $component = Livewire::test(TaskList::class);
    $checklist = $component->get('setupChecklist');
    expect($checklist[0]['done'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Requester, preview link, PR filter, sorting
|--------------------------------------------------------------------------
*/

test('it shows the requester when known', function () {
    $task = YakTask::factory()->create(['author_name' => 'Ada Lovelace']);

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="task-author-' . $task->id . '"')
        ->assertSee('Ada Lovelace');
});

test('it links to the live branch preview when one exists', function () {
    $repository = Repository::factory()->create(['slug' => 'preview-repo']);
    $task = YakTask::factory()->success()->create(['repo' => 'preview-repo', 'branch_name' => 'yak/preview-branch']);
    BranchDeployment::factory()->running()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/preview-branch',
        'hostname' => 'preview-repo-preview-branch.yak.example.com',
    ]);

    Livewire::test(TaskList::class)
        ->assertSeeHtml('data-testid="task-preview-link-' . $task->id . '"')
        ->assertSeeHtml('href="https://preview-repo-preview-branch.yak.example.com"');
});

test('it hides the preview link once the deployment is destroyed', function () {
    $repository = Repository::factory()->create(['slug' => 'gone-repo']);
    $task = YakTask::factory()->success()->create(['repo' => 'gone-repo', 'branch_name' => 'yak/gone-branch']);
    BranchDeployment::factory()->destroyed()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/gone-branch',
    ]);

    Livewire::test(TaskList::class)
        ->assertDontSeeHtml('data-testid="task-preview-link-' . $task->id . '"');
});

test('it filters by pr state', function () {
    YakTask::factory()->success()->create(['description' => 'Open PR task']);
    YakTask::factory()->merged()->create(['description' => 'Merged PR task']);
    YakTask::factory()->closedWithoutMerge()->create(['description' => 'Closed PR task']);
    YakTask::factory()->create(['description' => 'No PR task', 'pr_url' => null]);

    $component = Livewire::test(TaskList::class);

    $component->set('pr', 'open')
        ->assertSee('Open PR task')
        ->assertDontSee('Merged PR task')
        ->assertDontSee('Closed PR task')
        ->assertDontSee('No PR task');

    $component->set('pr', 'merged')
        ->assertSee('Merged PR task')
        ->assertDontSee('Open PR task');

    $component->set('pr', 'closed')
        ->assertSee('Closed PR task')
        ->assertDontSee('Merged PR task');

    $component->set('pr', 'none')
        ->assertSee('No PR task')
        ->assertDontSee('Open PR task');

    $component->call('clearFilters')
        ->assertSet('pr', '')
        ->assertSee('Open PR task')
        ->assertSee('No PR task');
});

test('it sorts by a column and flips direction on a second click', function () {
    YakTask::factory()->create(['repo' => 'zebra', 'description' => 'Zebra task', 'created_at' => now()]);
    YakTask::factory()->create(['repo' => 'apple', 'description' => 'Apple task', 'created_at' => now()->subDay()]);

    Livewire::test(TaskList::class)
        ->assertSeeInOrder(['Zebra task', 'Apple task'])
        ->call('sortBy', 'repo')
        ->assertSet('sort', 'repo')
        ->assertSet('direction', 'asc')
        ->assertSeeInOrder(['Apple task', 'Zebra task'])
        ->call('sortBy', 'repo')
        ->assertSet('direction', 'desc')
        ->assertSeeInOrder(['Zebra task', 'Apple task'])
        ->call('sortBy', 'created_at')
        ->assertSet('direction', 'desc')
        ->assertSeeInOrder(['Zebra task', 'Apple task']);
});

test('it ignores unknown sort columns', function () {
    YakTask::factory()->create(['description' => 'Only task']);

    Livewire::test(TaskList::class)
        ->set('sort', 'password')
        ->assertSee('Only task')
        ->call('sortBy', 'password')
        ->assertSet('sort', 'password');
});
