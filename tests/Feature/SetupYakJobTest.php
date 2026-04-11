<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\SetupYakJob;
use App\Livewire\Repos\RepoForm;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Successful Setup
|--------------------------------------------------------------------------
*/

test('successful setup transitions task to success and repo to ready', function () {
    fakeClaudeRun([
        'result' => 'Repository environment set up successfully',
        'cost_usd' => 3.00,
        'session_id' => 'sess_setup_ok',
        'num_turns' => 20,
        'duration_ms' => 180000,
    ], [
        'git pull *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'test-repo',
        'path' => '/home/yak/repos/test-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'mode' => TaskMode::Setup,
        'source' => 'dashboard',
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->session_id)->toBe('sess_setup_ok')
        ->and($task->result_summary)->toBe('Repository environment set up successfully')
        ->and((float) $task->cost_usd)->toBe(3.00)
        ->and($task->num_turns)->toBe(20)
        ->and($task->duration_ms)->toBe(180000)
        ->and($task->completed_at)->not->toBeNull()
        ->and($repository->setup_status)->toBe('ready');
});

test('setup transitions repo setup_status through running to ready on success', function () {
    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'run-repo',
        'path' => '/home/yak/repos/run-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'run-repo',
        'mode' => TaskMode::Setup,
    ]);

    $capturedStatuses = [];
    Repository::updating(function ($model) use (&$capturedStatuses) {
        if ($model->isDirty('setup_status')) {
            $capturedStatuses[] = $model->setup_status;
        }
    });

    $job = new SetupYakJob($task);
    $job->handle();

    expect($capturedStatuses)->toContain('running')
        ->toContain('ready');
});

test('setup increments attempts', function () {
    fakeClaudeRun(extraFakes: ['git pull *' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'att-repo',
        'path' => '/home/yak/repos/att-repo',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'att-repo',
        'mode' => TaskMode::Setup,
        'attempts' => 0,
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    $task->refresh();
    expect($task->attempts)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Setup stays on default branch (no new branch)
|--------------------------------------------------------------------------
*/

test('setup checks out default branch and pulls latest', function () {
    fakeClaudeRun(extraFakes: ['git pull *' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'branch-repo',
        'path' => '/home/yak/repos/branch-repo',
        'default_branch' => 'develop',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'branch-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git checkout develop'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'git pull origin develop'));
});

/*
|--------------------------------------------------------------------------
| Preflight Cleanup
|--------------------------------------------------------------------------
*/

test('preflight runs docker-compose stop and kills dev ports', function () {
    fakeClaudeRun(extraFakes: ['git pull *' => Process::result('')]);

    $repository = Repository::factory()->create([
        'slug' => 'pf-repo',
        'path' => '/home/yak/repos/pf-repo',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'pf-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'docker-compose stop');
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:8000'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:5173'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:3000'));
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

test('claude error marks task failed and repo setup_status failed', function () {
    fakeClaudeError('Docker compose failed to start');

    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'is_error' => true,
            'result' => 'Docker compose failed to start',
            'session_id' => 'sess_err',
            'cost_usd' => 0.25,
            'num_turns' => 1,
            'duration_ms' => 5000,
        ])),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'err-repo',
        'path' => '/home/yak/repos/err-repo',
        'setup_status' => 'pending',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'err-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Docker compose failed to start')
        ->and($task->completed_at)->not->toBeNull()
        ->and($repository->setup_status)->toBe('failed');
});

test('malformed claude output marks task as failed', function () {
    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result('not valid json'),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'bad-repo',
        'path' => '/home/yak/repos/bad-repo',
    ]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'bad-repo',
        'mode' => TaskMode::Setup,
    ]);

    $job = new SetupYakJob($task);
    $job->handle();

    $task->refresh();
    $repository->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->not->toBeEmpty()
        ->and($repository->setup_status)->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| Queue Configuration
|--------------------------------------------------------------------------
*/

test('SetupYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->pending()->make();
    $job = new SetupYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('SetupYakJob has CleanupDevEnvironment middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'mw-repo']);

    $job = new SetupYakJob($task);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(EnsureDailyBudget::class)
        ->and($middleware[1])->toBeInstanceOf(CleanupDevEnvironment::class);
});

/*
|--------------------------------------------------------------------------
| Dashboard dispatch
|--------------------------------------------------------------------------
*/

test('RepoForm dispatches SetupYakJob when creating repo', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create());

    Livewire\Livewire::test(RepoForm::class)
        ->set('name', 'New Repo')
        ->set('slug', 'new-repo')
        ->set('path', '/home/yak/repos/new-repo')
        ->set('ci_system', 'github_actions')
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->mode === TaskMode::Setup
            && $job->task->repo === 'new-repo';
    });
});

test('RepoForm dispatches SetupYakJob on rerun setup', function () {
    Queue::fake();

    $this->actingAs(User::factory()->create());
    $repository = Repository::factory()->create([
        'slug' => 'rerun-repo',
        'path' => '/home/yak/repos/rerun-repo',
        'setup_status' => 'failed',
    ]);

    Livewire\Livewire::test(RepoForm::class, ['repository' => $repository])
        ->call('rerunSetup')
        ->assertHasNoErrors();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->repo === 'rerun-repo';
    });
});

/*
|--------------------------------------------------------------------------
| Artisan Command
|--------------------------------------------------------------------------
*/

test('yak:setup-repo command dispatches SetupYakJob', function () {
    Queue::fake();

    $repository = Repository::factory()->create([
        'slug' => 'cmd-repo',
        'name' => 'Command Repo',
        'path' => '/home/yak/repos/cmd-repo',
    ]);

    $this->artisan('yak:setup-repo', ['slug' => 'cmd-repo'])
        ->assertSuccessful();

    Queue::assertPushed(SetupYakJob::class, function ($job) {
        return $job->task->repo === 'cmd-repo'
            && $job->task->mode === TaskMode::Setup;
    });

    $repository->refresh();
    expect($repository->setup_status)->toBe('pending')
        ->and($repository->setup_task_id)->not->toBeNull();
});

test('yak:setup-repo command fails for unknown repo', function () {
    $this->artisan('yak:setup-repo', ['slug' => 'nonexistent-repo'])
        ->assertFailed();
});

/*
|--------------------------------------------------------------------------
| Prompt Template
|--------------------------------------------------------------------------
*/

test('setup prompt template includes repo name and setup steps', function () {
    $prompt = YakPromptBuilder::setupPrompt('My Project');

    expect($prompt)
        ->toContain('My Project')
        ->toContain('docker-compose up -d')
        ->toContain('Install dependencies')
        ->toContain('Run database migrations')
        ->toContain('Do NOT make any code changes');
});

test('taskPrompt routes setup mode to setup template', function () {
    $task = YakTask::factory()->pending()->make([
        'mode' => TaskMode::Setup,
        'repo' => 'test-repo',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task, ['repo_name' => 'Test Repo']);

    expect($prompt)
        ->toContain('Test Repo')
        ->toContain('Set up the development environment');
});
