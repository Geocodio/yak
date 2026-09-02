<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
});

test('it requires authentication', function () {
    auth()->logout();

    $this->get(route('tasks'))->assertRedirect(route('login'));
});

test('index renders task rows', function () {
    $task = YakTask::factory()->running()->create([
        'description' => 'Fix something important',
        'repo' => 'my-repo',
        'source' => 'slack',
        'external_id' => 'SLACK-001',
        'author_name' => 'Ada Lovelace',
    ]);

    $this->get(route('tasks'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Index')
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('description', 'Fix something important')
                ->where('repo', 'my-repo')
                ->where('sourceLabel', 'Slack')
                ->where('externalId', 'SLACK-001')
                ->where('by', 'Ada Lovelace')
                ->where('status', 'running')
                ->etc()));
});

test('index exposes active repositories for the new task sheet', function () {
    Repository::factory()->create(['slug' => 'active-one', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'inactive-one', 'is_active' => false]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeRepos', ['active-one']));
});

test('it shows status for every task status', function () {
    foreach (TaskStatus::cases() as $status) {
        YakTask::factory()->create(['status' => $status]);
    }

    $response = $this->get(route('tasks'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tasks/Index')
            ->has('tasks.data', count(TaskStatus::cases())));

    $rows = collect($response->viewData('page')['props']['tasks']['data']);
    $actual = $rows->pluck('status')->sort()->values()->all();
    $expected = collect(TaskStatus::cases())->map(fn (TaskStatus $status) => $status->value)->sort()->values()->all();

    expect($actual)->toBe($expected);
});

test('pr fields are serialized for a task with a pull request', function () {
    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/42',
        'pr_number' => 42,
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('pr.number', 42)
                ->where('pr.state', 'open')
                ->where('pr.url', 'https://github.com/org/repo/pull/42')
                ->etc()));
});

test('it paginates tasks', function () {
    YakTask::factory()->count(51)->create();

    $this->get(route('tasks'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.last_page', 2)
            ->has('tasks.data', 50));

    $this->get(route('tasks', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1));
});

test('filters by status', function () {
    YakTask::factory()->running()->create(['description' => 'Running task']);
    YakTask::factory()->success()->create(['description' => 'Success task']);

    $this->get(route('tasks', ['status' => 'running']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'Running task')->etc()));
});

test('filters by source and repo', function () {
    YakTask::factory()->create(['source' => 'slack', 'description' => 'Slack task']);
    YakTask::factory()->create(['source' => 'sentry', 'description' => 'Sentry task']);

    $this->get(route('tasks', ['source' => 'slack']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'Slack task')->etc()));

    YakTask::factory()->create(['repo' => 'api', 'description' => 'API task']);
    YakTask::factory()->create(['repo' => 'web', 'description' => 'Web task']);

    $this->get(route('tasks', ['repo' => 'api']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'API task')->etc()));
});

test('filters by pr state', function () {
    YakTask::factory()->success()->create(['description' => 'Open PR task']);
    YakTask::factory()->merged()->create(['description' => 'Merged PR task']);
    YakTask::factory()->closedWithoutMerge()->create(['description' => 'Closed PR task']);
    YakTask::factory()->create(['description' => 'No PR task', 'pr_url' => null]);

    $this->get(route('tasks', ['pr' => 'open']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'Open PR task')->etc()));

    $this->get(route('tasks', ['pr' => 'merged']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'Merged PR task')->etc()));

    $this->get(route('tasks', ['pr' => 'closed']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'Closed PR task')->etc()));

    $this->get(route('tasks', ['pr' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->where('description', 'No PR task')->etc()));
});

test('source filter only applies on the tasks tab', function () {
    YakTask::factory()->create(['source' => 'slack', 'mode' => TaskMode::Setup]);

    $this->get(route('tasks', ['tab' => 'setup', 'source' => 'sentry']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1));
});

test('sorts by created_at desc by default and flips direction', function () {
    YakTask::factory()->create(['repo' => 'zebra', 'description' => 'Zebra task', 'created_at' => now()]);
    YakTask::factory()->create(['repo' => 'apple', 'description' => 'Apple task', 'created_at' => now()->subDay()]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.data.0.description', 'Zebra task')
            ->where('tasks.data.1.description', 'Apple task'));

    $this->get(route('tasks', ['sort' => 'repo', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.data.0.description', 'Apple task')
            ->where('tasks.data.1.description', 'Zebra task'));

    $this->get(route('tasks', ['sort' => 'repo', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tasks.data.0.description', 'Zebra task')
            ->where('tasks.data.1.description', 'Apple task'));
});

test('unknown sort columns are ignored and fall back to created_at', function () {
    YakTask::factory()->create(['description' => 'Only task', 'created_at' => now()]);

    $this->get(route('tasks', ['sort' => 'password']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'password')
            ->has('tasks.data', 1));
});

test('tabs switch between tasks, reviews and setup with counts', function () {
    YakTask::factory()->count(3)->create(['mode' => TaskMode::Fix]);
    YakTask::factory()->count(2)->create(['mode' => TaskMode::Setup]);
    YakTask::factory()->count(1)->create(['mode' => TaskMode::Review]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.tasks', 3)
            ->where('counts.setup', 2)
            ->where('counts.reviews', 1)
            ->where('filters.tab', 'tasks')
            ->has('tasks.data', 3));

    $this->get(route('tasks', ['tab' => 'setup']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 2));

    $this->get(route('tasks', ['tab' => 'reviews']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 1));
});

test('default tab shows only fix and research tasks', function () {
    YakTask::factory()->create(['mode' => TaskMode::Fix, 'description' => 'Fix task A']);
    YakTask::factory()->create(['mode' => TaskMode::Research, 'description' => 'Research task B']);
    YakTask::factory()->create(['mode' => TaskMode::Setup, 'description' => 'Setup task C']);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->has('tasks.data', 2));
});

test('follow-up tasks nest under their parent and parent status follows the latest', function () {
    $root = YakTask::factory()->success()->create([
        'repo' => 'web',
        'external_id' => 'ROOT-1',
        'branch_name' => 'yak/R-1',
        'created_at' => now()->subHour(),
    ]);
    YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'web',
        'external_id' => 'ROOT-1-followup-1',
        'branch_name' => 'yak/R-1',
        'description' => 'first follow up change',
        'status' => TaskStatus::Running,
        'created_at' => now()->subMinutes(30),
    ]);
    $latestChild = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'repo' => 'web',
        'external_id' => 'ROOT-1-followup-2',
        'branch_name' => 'yak/R-1',
        'description' => 'second follow up change',
        'status' => TaskStatus::Failed,
        'created_at' => now()->subMinutes(10),
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $root->id)
                ->where('status', 'failed')
                ->has('followUps', 2)
                ->where('followUps.0.description', 'first follow up change')
                ->where('followUps.1.description', 'second follow up change')
                ->etc()));

    // Only the root is a top-level paginated row.
    $ids = collect($this->get(route('tasks'))->viewData('page')['props']['tasks']['data'])->pluck('id');
    expect($ids)->toContain($root->id)->not->toContain($latestChild->id);
});

test('a multi-level follow-up chain flattens grandchildren under the root', function () {
    $root = YakTask::factory()->success()->create(['repo' => 'web', 'external_id' => 'GC-1', 'branch_name' => 'yak/GC-1']);
    $child = YakTask::factory()->create([
        'parent_task_id' => $root->id, 'repo' => 'web',
        'external_id' => 'GC-1-followup-1', 'branch_name' => 'yak/GC-1',
    ]);
    YakTask::factory()->create([
        'parent_task_id' => $child->id, 'repo' => 'web',
        'external_id' => 'GC-1-followup-2', 'branch_name' => 'yak/GC-1',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row->has('followUps', 2)->etc()));
});

test('tab counts exclude follow-up children', function () {
    $root = YakTask::factory()->success()->create(['repo' => 'web', 'branch_name' => 'yak/TC-1']);
    YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => 'web', 'branch_name' => 'yak/TC-1']);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('counts.tasks', 1));
});

test('preview gif url is present when an artifact preview exists', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();
    Artifact::factory()->for($task, 'task')->create([
        'type' => 'video_thumbnail',
        'role' => 'preview',
        'filename' => 'walkthrough-preview.gif',
        'disk_path' => 'walkthrough-preview.gif',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->whereType('previewUrl', 'string')
                ->whereType('previewGif', 'string')
                ->etc()));
});

test('a task with only a poster has no preview gif', function () {
    $task = YakTask::factory()->success()->create();
    Artifact::factory()->for($task, 'task')->videoThumbnail()->create();

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->whereType('previewUrl', 'string')
                ->where('previewGif', null)
                ->etc()));
});

test('a task with no artifacts has no preview images', function () {
    YakTask::factory()->success()->create();

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('previewUrl', null)
                ->where('previewGif', null)
                ->etc()));
});

test('it links to the live branch preview when one exists', function () {
    $repository = Repository::factory()->create(['slug' => 'preview-repo']);
    $task = YakTask::factory()->success()->create(['repo' => 'preview-repo', 'branch_name' => 'yak/preview-branch']);
    BranchDeployment::factory()->running()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/preview-branch',
        'hostname' => 'preview-repo-preview-branch.yak.example.com',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('deploymentUrl', 'https://preview-repo-preview-branch.yak.example.com')
                ->etc()));
});

test('it hides the preview link once the deployment is destroyed', function () {
    $repository = Repository::factory()->create(['slug' => 'gone-repo']);
    $task = YakTask::factory()->success()->create(['repo' => 'gone-repo', 'branch_name' => 'yak/gone-branch']);
    BranchDeployment::factory()->destroyed()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'yak/gone-branch',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('deploymentUrl', null)
                ->etc()));
});

test('repo column links to the pr when the task has one', function () {
    Repository::factory()->create(['slug' => 'pr-project']);
    $task = YakTask::factory()->success()->create([
        'repo' => 'pr-project',
        'pr_url' => 'https://github.com/org/pr-project/pull/7',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('repoUrl', 'https://github.com/org/pr-project/pull/7')
                ->etc()));
});

test('repo column links to repository edit page when repository exists', function () {
    Repository::factory()->create(['slug' => 'my-project']);
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'my-project',
        'pr_url' => null,
        'description' => 'Linked task',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('repoUrl', route('repos.edit', 'my-project'))
                ->etc()));
});

test('repo column has no link when no repository record exists', function () {
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'ghost-repo',
        'pr_url' => null,
        'description' => 'Orphan task',
    ]);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tasks.data', 1, fn (Assert $row) => $row
                ->where('id', $task->id)
                ->where('repoUrl', null)
                ->etc()));
});

test('setup card shows until dismissed', function () {
    User::query()->delete();
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->has('setupCard'));

    $this->post(route('tasks.setup-card.dismiss'))->assertRedirect();

    expect($user->fresh()->has_seen_setup_card_at)->not->toBeNull();

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('setupCard', null));
});

test('setup card is hidden once repos and tasks both exist', function () {
    User::query()->delete();
    $user = User::factory()->create(['has_seen_setup_card_at' => null]);
    $this->actingAs($user);

    Repository::factory()->create();
    YakTask::factory()->create();

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('setupCard', null));
});

test('new task query param is passed through', function () {
    $this->get(route('tasks', ['new' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('openNew', true));

    $this->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page->where('openNew', false));
});
