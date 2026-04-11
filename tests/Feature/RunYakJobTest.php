<?php

use App\Enums\TaskStatus;
use App\Jobs\Middleware\CleanupDevEnvironment;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Successful Run
|--------------------------------------------------------------------------
*/

test('successful run transitions task to awaiting_ci and pushes branch', function () {
    Process::fake([
        'docker-compose stop' => Process::result(''),
        'lsof *' => Process::result(''),
        'git fetch *' => Process::result(''),
        'git checkout -b *' => Process::result(''),
        'git checkout *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Fixed the bug successfully',
            'cost_usd' => 2.50,
            'session_id' => 'sess_success123',
            'num_turns' => 15,
            'duration_ms' => 120000,
            'is_error' => false,
        ])),
        'git push *' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->session_id)->toBe('sess_success123')
        ->and($task->result_summary)->toBe('Fixed the bug successfully')
        ->and((float) $task->cost_usd)->toBe(2.50)
        ->and($task->num_turns)->toBe(15)
        ->and($task->duration_ms)->toBe(120000)
        ->and($task->branch_name)->toStartWith('yak/');

    Process::assertRan(fn ($process) => str_contains($process->command, 'git push'));
});

test('successful run creates branch with yak/{external_id} naming', function () {
    Process::fake([
        '*' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'my-repo',
        'external_id' => 'ISSUE-42',
    ]);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->branch_name)->toBe('yak/ISSUE-42');

    Process::assertRan(fn ($process) => str_contains($process->command, 'git checkout -b yak/ISSUE-42'));
});

test('successful run increments attempts', function () {
    Process::fake([
        '*' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'attempts' => 0]);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();
    expect($task->attempts)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Preflight Cleanup
|--------------------------------------------------------------------------
*/

test('preflight runs docker-compose stop and kills dev ports', function () {
    Process::fake([
        '*' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo']);

    $job = new RunYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => $process->command === 'docker-compose stop');
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:8000'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:5173'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'lsof -ti:3000'));
});

/*
|--------------------------------------------------------------------------
| Clarification Detection
|--------------------------------------------------------------------------
*/

test('clarification from slack source sets awaiting_clarification status', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'clarification_needed' => true,
            'options' => ['Fix the auth flow', 'Fix the API endpoint', 'Both'],
            'session_id' => 'sess_clarify',
            'cost_usd' => 0.75,
            'num_turns' => 5,
            'duration_ms' => 30000,
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'source' => 'slack']);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->session_id)->toBe('sess_clarify')
        ->and($task->clarification_options)->toBe(['Fix the auth flow', 'Fix the API endpoint', 'Both'])
        ->and($task->clarification_expires_at)->not->toBeNull()
        ->and((float) $task->cost_usd)->toBe(0.75)
        ->and($task->num_turns)->toBe(5);
});

test('clarification from non-slack source is treated as success', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'clarification_needed' => true,
            'options' => ['Option A'],
            'session_id' => 'sess_linear_clarify',
            'cost_usd' => 0.50,
            'num_turns' => 3,
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo', 'source' => 'linear']);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::AwaitingCi);
});

/*
|--------------------------------------------------------------------------
| Claude Error
|--------------------------------------------------------------------------
*/

test('claude error response marks task as failed', function () {
    Process::fake([
        'claude *' => Process::result(json_encode([
            'is_error' => true,
            'result' => 'Rate limited by API',
            'session_id' => 'sess_err',
        ])),
        '*' => Process::result(''),
    ]);

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo']);

    $job = new RunYakJob($task);
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

    $repository = Repository::factory()->create(['slug' => 'my-repo', 'path' => '/home/yak/repos/my-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'my-repo']);

    $job = new RunYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Prompt Assembly
|--------------------------------------------------------------------------
*/

test('assembles prompt based on task source', function () {
    $sources = ['slack', 'linear', 'sentry', 'flaky-test', 'manual'];

    foreach ($sources as $source) {
        Process::fake([
            'claude *' => Process::result(json_encode([
                'result' => 'Done',
                'session_id' => 'sess_prompt',
            ])),
            '*' => Process::result(''),
        ]);

        $repository = Repository::factory()->create(['path' => '/home/yak/repos/repo-'.$source]);
        $task = YakTask::factory()->pending()->create([
            'repo' => $repository->slug,
            'source' => $source,
            'description' => 'Test description for '.$source,
        ]);

        $job = new RunYakJob($task);
        $job->handle();

        Process::assertRan(fn ($process) => str_contains($process->command, 'claude'));
    }
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('RunYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->pending()->make();
    $job = new RunYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

test('RunYakJob has CleanupDevEnvironment middleware', function () {
    Process::fake();

    $repository = Repository::factory()->create(['slug' => 'mw-repo', 'path' => '/home/yak/repos/mw-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'mw-repo']);

    $job = new RunYakJob($task);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(EnsureDailyBudget::class)
        ->and($middleware[1])->toBeInstanceOf(CleanupDevEnvironment::class);
});
