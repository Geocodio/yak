<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSource;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['name' => 'Mathias Hansen']);
    $this->actingAs($this->user);
    $this->repository = Repository::factory()->create(['slug' => 'acme/site', 'sandbox_snapshot' => 'acme-site-v1', 'sandbox_base_version' => 6, 'test_command' => 'make test', 'preview_manifest' => ['port' => 8000, 'cold_start' => 'make serve']]);
});

function setupRun(array $attributes = []): YakTask
{
    return YakTask::factory()->create([
        'repo' => 'acme/site',
        'mode' => TaskMode::Setup,
        'external_id' => 'setup-' . fake()->unique()->lexify('????????'),
        'source' => 'dashboard',
        ...$attributes,
    ]);
}

function researchRun(array $attributes = [], ?Thread $thread = null): YakTask
{
    return YakTask::factory()->create([
        'repo' => 'acme/site',
        'mode' => TaskMode::Research,
        'external_id' => 'risk-profile-' . fake()->uuid(),
        'source' => 'dashboard',
        'status' => TaskStatus::Success,
        'thread_id' => $thread?->id,
        ...$attributes,
    ]);
}

test('the tab lists setup and research runs newest first, and nothing else', function () {
    $older = setupRun(['status' => TaskStatus::Success, 'created_at' => now()->subDays(2), 'num_turns' => 16, 'duration_ms' => 120000, 'cost_usd' => 0.3, 'started_by_user_id' => $this->user->id]);
    $research = researchRun(['created_at' => now()->subDay(), 'cost_usd' => 0.07]);
    $failed = setupRun(['status' => TaskStatus::Failed, 'created_at' => now(), 'error_log' => "port 8000 never answered\nmore detail"]);
    YakTask::factory()->create(['repo' => 'acme/site', 'mode' => TaskMode::Fix]);
    YakTask::factory()->create(['repo' => 'acme/other', 'mode' => TaskMode::Setup]);

    $this->get(route('repos.runs.index', $this->repository))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Repositories/Runs')
            ->has('runs', 3)
            ->where('runs.0.id', $failed->public_id)
            ->where('runs.0.kind', 'setup')
            ->where('runs.0.status', 'failed')
            ->where('runs.0.resultLine', 'Failed: port 8000 never answered')
            ->where('runs.1.id', $research->public_id)
            ->where('runs.1.kind', 'repository_research')
            ->where('runs.1.status', 'done')
            ->where('runs.1.resultLine', 'Done')
            ->where('runs.1.startedBy', 'Yak')
            ->where('runs.2.id', $older->public_id)
            ->where('runs.2.status', 'ready')
            ->where('runs.2.resultLine', 'Ready, 16 steps, 2m')
            ->where('runs.2.startedBy', 'Mathias Hansen')
            ->where('runs.2.costUsd', 0.3)
            ->where('selected.id', $failed->public_id));
});

test('a setup the system started names its reason', function () {
    setupRun(['status' => TaskStatus::Success, 'source' => 'system', 'description' => 'Re-provision sandbox for site (yak-base updated)']);

    $this->get(route('repos.runs.index', $this->repository))
        ->assertInertia(fn (Assert $page) => $page->where('runs.0.startedBy', 'Yak, after a base image update'));
});

test('the filters narrow by kind and status', function () {
    setupRun(['status' => TaskStatus::Success]);
    setupRun(['status' => TaskStatus::Failed, 'error_log' => 'boom']);
    researchRun();

    $this->get(route('repos.runs.index', [$this->repository, 'kind' => 'setup']))
        ->assertInertia(fn (Assert $page) => $page->has('runs', 2)->where('filters.kind', 'setup')->where('totalCount', 2));
    $this->get(route('repos.runs.index', [$this->repository, 'status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->has('runs', 1)->where('runs.0.status', 'failed'));
    $this->get(route('repos.runs.index', [$this->repository, 'kind' => 'repository_research', 'status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0)->where('selected', null));
});

test('without runs the page offers to run setup', function () {
    $this->get(route('repos.runs.index', $this->repository))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0)->where('selected', null)->where('header.slug', 'acme/site'));
});

test('a failed setup run shows its error, can be retried and links its log', function () {
    $run = setupRun(['status' => TaskStatus::Failed, 'error_log' => str_repeat('x', 900)]);

    $this->get(route('repos.runs.show', [$this->repository, $run]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.id', $run->public_id)
            ->where('selected.status', 'failed')
            ->where('selected.canRetry', true)
            ->where('selected.errorIsTruncated', true)
            ->where('selected.error', fn ($error) => mb_strlen($error) <= 601)
            ->where('selected.remembered', null)
            ->where('selected.logUrl', route('repos.runs.log', [$this->repository, $run]))
            ->where('selected.threadUrl', null)
            ->where('settingsUrl', route('repos.settings.show', [$this->repository, 'sandbox'])));
});

test('the newest successful setup run shows what Yak remembers and the threads that wait', function () {
    $newest = setupRun(['status' => TaskStatus::Success, 'result_summary' => 'The **dev environment** works.']);
    $older = setupRun(['status' => TaskStatus::Success, 'created_at' => now()->subDay()]);
    $waiting = Thread::factory()->create(['repo' => 'acme/site', 'blocked_reason' => ThreadBlockReason::WaitingForRepository, 'title' => 'Update the landing page']);
    Thread::factory()->private()->create(['repo' => 'acme/site', 'blocked_reason' => ThreadBlockReason::WaitingForRepository, 'title' => 'Someone private']);
    Thread::factory()->create(['repo' => 'acme/other', 'blocked_reason' => ThreadBlockReason::WaitingForRepository]);

    $this->get(route('repos.runs.show', [$this->repository, $newest]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.answerHtml', fn ($html) => str_contains($html, '<strong>dev environment</strong>'))
            ->where('selected.remembered', ['testCommand' => 'make test', 'previewPort' => 8000, 'startCommand' => 'make serve', 'snapshot' => 'Saved, sandbox base version 6'])
            ->has('selected.waitingThreads', 1)
            ->where('selected.waitingThreads.0.title', 'Update the landing page')
            ->where('selected.waitingThreads.0.url', route('threads.show', $waiting)));

    $this->get(route('repos.runs.show', [$this->repository, $older]))
        ->assertInertia(fn (Assert $page) => $page->where('selected.remembered', null)->where('selected.waitingThreads', []));
});

test('a research run links the risk profile and, when visible, its thread', function () {
    $thread = Thread::factory()->create(['repo' => 'acme/site', 'source' => ThreadSource::RepositoryResearch]);
    $run = researchRun([], $thread);

    $this->get(route('repos.runs.show', [$this->repository, $run]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.kind', 'repository_research')
            ->where('selected.canRetry', false)
            ->where('selected.riskProfileUrl', route('repos.risk-profile.show', $this->repository))
            ->where('selected.threadUrl', route('threads.show', $thread)));
});

test('a research run of a private thread of someone else is hidden everywhere', function () {
    $thread = Thread::factory()->private()->create(['repo' => 'acme/site', 'source' => ThreadSource::RepositoryResearch]);
    $run = researchRun([], $thread);

    $this->get(route('repos.runs.index', $this->repository))->assertInertia(fn (Assert $page) => $page->has('runs', 0));
    $this->get(route('repos.runs.show', [$this->repository, $run]))->assertNotFound();
    $this->getJson(route('repos.runs.log', [$this->repository, $run]))->assertNotFound();
});

test('a run of another repository or another kind answers 404', function () {
    $foreign = YakTask::factory()->create(['repo' => 'acme/other', 'mode' => TaskMode::Setup]);
    $fix = YakTask::factory()->create(['repo' => 'acme/site', 'mode' => TaskMode::Fix]);

    foreach ([$foreign, $fix] as $task) {
        $this->get(route('repos.runs.show', [$this->repository, $task]))->assertNotFound();
        $this->getJson(route('repos.runs.log', [$this->repository, $task]))->assertNotFound();
        $this->post(route('repos.runs.retry', [$this->repository, $task]))->assertNotFound();
    }
});

test('the log answers the entries of the run', function () {
    $run = setupRun(['status' => TaskStatus::Success]);
    TaskLog::create(['yak_task_id' => $run->id, 'level' => 'info', 'message' => 'Installing packages']);

    $this->getJson(route('repos.runs.log', [$this->repository, $run]))
        ->assertOk()
        ->assertJsonPath('entries.0.text', 'Installing packages')
        ->assertJsonPath('nextCursor', null);
});

test('retrying a failed setup run starts it again and lands on the run', function () {
    $run = setupRun(['status' => TaskStatus::Failed, 'error_log' => 'boom']);

    $this->post(route('repos.runs.retry', [$this->repository, $run]))
        ->assertRedirect(route('repos.runs.show', [$this->repository, $run]));

    expect($run->fresh()->status)->toBe(TaskStatus::Pending);
});

test('only a setup run can be retried', function () {
    $run = researchRun(['status' => TaskStatus::Failed, 'error_log' => 'boom']);

    $this->post(route('repos.runs.retry', [$this->repository, $run]))->assertNotFound();
});

test('guests are sent to log in', function () {
    auth()->logout();

    $this->get(route('repos.runs.index', $this->repository))->assertRedirect(route('login'));
});

test('the tab sits after Sandbox in the settings sections', function () {
    $this->get(route('repos.runs.index', $this->repository))
        ->assertInertia(fn (Assert $page) => $page
            ->where('header.sections.5.slug', 'runs')
            ->where('header.sections.5.url', route('repos.runs.index', $this->repository)));
});

test('running setup or research again lands on the new run', function () {
    $this->post(route('repos.rerun-setup', $this->repository));
    $setup = YakTask::where('repo', 'acme/site')->where('mode', TaskMode::Setup)->latest('id')->first();

    $this->post(route('repos.risk-profile', $this->repository), ['action' => 'generate'])
        ->assertRedirect(route('repos.runs.show', [$this->repository, YakTask::where('mode', TaskMode::Research)->latest('id')->first()]));

    expect($setup)->not->toBeNull();
});
