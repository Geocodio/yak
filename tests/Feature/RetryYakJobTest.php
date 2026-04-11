<?php

use App\Enums\TaskStatus;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\RetryYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Successful Retry
|--------------------------------------------------------------------------
*/

test('successful retry transitions task to awaiting_ci and force pushes branch', function () {
    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Fixed the CI failures',
            'cost_usd' => 1.50,
            'session_id' => 'sess_retry_new',
            'num_turns' => 10,
            'duration_ms' => 90000,
            'is_error' => false,
        ])),
        'git push *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'test-repo',
        'session_id' => 'sess_original',
        'branch_name' => 'yak/ISSUE-100',
        'cost_usd' => 2.00,
        'num_turns' => 15,
        'duration_ms' => 120000,
    ]);

    $job = new RetryYakJob($task, 'Tests failed: 2 errors');
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_retry_new')
        ->and($task->result_summary)->toBe('Fixed the CI failures')
        ->and((float) $task->cost_usd)->toBe(3.50)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(210000);

    Process::assertRan(fn ($process) => str_contains($process->command, 'git push --force'));
});

test('successful retry accumulates cost and turns on task', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'cost_usd' => 0.75,
            'session_id' => 'sess_2',
            'num_turns' => 5,
            'duration_ms' => 30000,
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'acc-repo', 'path' => '/home/yak/repos/acc-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'acc-repo',
        'session_id' => 'sess_1',
        'branch_name' => 'yak/ACC-1',
        'cost_usd' => 3.00,
        'num_turns' => 20,
        'duration_ms' => 150000,
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    $task->refresh();

    expect((float) $task->cost_usd)->toBe(3.75)
        ->and($task->num_turns)->toBe(25)
        ->and($task->duration_ms)->toBe(180000);
});

/*
|--------------------------------------------------------------------------
| Branch Checkout
|--------------------------------------------------------------------------
*/

test('checks out existing task branch instead of creating new', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'br-repo', 'path' => '/home/yak/repos/br-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'br-repo',
        'external_id' => 'ISSUE-42',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ISSUE-42',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'git checkout yak/ISSUE-42');
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'git checkout -b'));
});

/*
|--------------------------------------------------------------------------
| Preflight Cleanup
|--------------------------------------------------------------------------
*/

test('preflight runs docker-compose stop and kills dev ports', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'pf-repo', 'path' => '/home/yak/repos/pf-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'pf-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/PF-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'docker-compose stop');
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:8000'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:5173'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:3000'));
});

/*
|--------------------------------------------------------------------------
| Claude --resume Flag
|--------------------------------------------------------------------------
*/

test('invokes claude with --resume flag and session_id', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_resumed',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'res-repo', 'path' => '/home/yak/repos/res-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'res-repo',
        'session_id' => 'sess_original_123',
        'branch_name' => 'yak/RES-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'claude')
            && str_contains($process->command, '--resume')
            && str_contains($process->command, 'sess_original_123');
    });
});

test('claude command includes all standard flags', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'fl-repo', 'path' => '/home/yak/repos/fl-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'fl-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/FL-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    Process::assertRan(function ($process) {
        $cmd = $process->command;

        return str_contains($cmd, '--dangerously-skip-permissions')
            && str_contains($cmd, '--bare')
            && str_contains($cmd, '--output-format json')
            && str_contains($cmd, '--model')
            && str_contains($cmd, '--max-turns')
            && str_contains($cmd, '--max-budget-usd')
            && str_contains($cmd, '--append-system-prompt');
    });
});

/*
|--------------------------------------------------------------------------
| Retry Prompt
|--------------------------------------------------------------------------
*/

test('retry prompt includes CI failure output', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'pr-repo', 'path' => '/home/yak/repos/pr-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'pr-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/PR-1',
    ]);

    $failureOutput = 'FAIL tests/Feature/AuthTest.php - Expected 200 got 500';

    $job = new RetryYakJob($task, $failureOutput);
    $job->handle();

    Process::assertRan(function ($process) use ($failureOutput) {
        return str_contains($process->command, 'claude')
            && str_contains($process->command, 'CI')
            && str_contains($process->command, $failureOutput);
    });
});

test('retry prompt handles null failure output gracefully', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'nf-repo', 'path' => '/home/yak/repos/nf-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'nf-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/NF-1',
    ]);

    $job = new RetryYakJob($task, null);
    $job->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'claude'));

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingCi);
});

/*
|--------------------------------------------------------------------------
| Claude Error
|--------------------------------------------------------------------------
*/

test('claude error response marks task as failed and checks out default branch', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'is_error' => true,
            'result' => 'Rate limited by API',
            'session_id' => 'sess_err',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'err-repo', 'path' => '/home/yak/repos/err-repo', 'default_branch' => 'main']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'err-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/ERR-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git checkout main'));
});

test('malformed claude output marks task as failed', function () {
    Process::fake([
        'claude *' => Process::result('not json at all {{'),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'mf-repo', 'path' => '/home/yak/repos/mf-repo']);
    $task = YakTask::factory()->retrying()->create([
        'repo' => 'mf-repo',
        'session_id' => 'sess_orig',
        'branch_name' => 'yak/MF-1',
    ]);

    $job = new RetryYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('RetryYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->retrying()->make();
    $job = new RetryYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('RetryYakJob has CleanupDevEnvironment middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->retrying()->create(['repo' => 'mw-repo']);

    $job = new RetryYakJob($task);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(EnsureDailyBudget::class)
        ->and($middleware[1])->toBeInstanceOf(CleanupDevEnvironment::class);
});
