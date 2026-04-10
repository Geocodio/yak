<?php

use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/*
|--------------------------------------------------------------------------
| Successful Research
|--------------------------------------------------------------------------
*/

test('successful research transitions task to success with result_summary and completed_at', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Found 3 key areas for improvement',
            'cost_usd' => 1.25,
            'session_id' => 'sess_research_1',
            'num_turns' => 10,
            'duration_ms' => 90000,
            'is_error' => false,
        ])),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'slack',
        'mode' => 'research',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('Found 3 key areas for improvement')
        ->and((float) $task->cost_usd)->toBe(1.25)
        ->and($task->session_id)->toBe('sess_research_1')
        ->and($task->num_turns)->toBe(10)
        ->and($task->duration_ms)->toBe(90000)
        ->and($task->completed_at)->not->toBeNull();
});

test('research ensures repo is on default branch and pulls latest', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    $repository = Repository::factory()->create([
        'slug' => 'test-repo',
        'path' => '/home/yak/repos/test-repo',
        'default_branch' => 'main',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'git checkout main'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'git pull origin main'));
});

test('research does not create any branch', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_1',
        ])),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle();

    $task->refresh();
    expect($task->branch_name)->toBeNull();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'git checkout -b'));
});

/*
|--------------------------------------------------------------------------
| HTML Artifact Collection
|--------------------------------------------------------------------------
*/

test('collects HTML artifact from .yak-artifacts/research.html', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Research complete',
            'session_id' => 'sess_artifact',
        ])),
    ]);
    Http::fake();

    File::shouldReceive('exists')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(true);
    File::shouldReceive('size')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(4096);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle();

    $artifact = Artifact::where('yak_task_id', $task->id)->first();

    expect($artifact)->not->toBeNull()
        ->and($artifact->type)->toBe('research')
        ->and($artifact->filename)->toBe('research.html')
        ->and($artifact->disk_path)->toBe('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->and($artifact->size_bytes)->toBe(4096);
});

test('handles missing HTML artifact gracefully', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Research complete',
            'session_id' => 'sess_no_artifact',
        ])),
    ]);
    Http::fake();

    File::shouldReceive('exists')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(false);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success);
    expect(Artifact::where('yak_task_id', $task->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Linear Notification
|--------------------------------------------------------------------------
*/

test('posts summary and findings URL as Linear comment', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Codebase audit complete',
            'session_id' => 'sess_linear',
        ])),
    ]);
    Http::fake();

    File::shouldReceive('exists')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(true);
    File::shouldReceive('size')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(2048);

    config(['yak.channels.linear.api_key' => 'lin_api_test_key']);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'linear',
        'external_id' => 'LIN-123',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.linear.app/graphql') {
            return false;
        }

        $body = $request->data();

        if (str_contains($body['query'] ?? '', 'commentCreate')) {
            return str_contains($body['variables']['body'] ?? '', 'Codebase audit complete')
                && str_contains($body['variables']['body'] ?? '', '/artifacts/')
                && ($body['variables']['issueId'] ?? '') === 'LIN-123';
        }

        return false;
    });
});

test('moves Linear issue to Done state', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Done',
            'session_id' => 'sess_linear_done',
        ])),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    config(['yak.channels.linear.api_key' => 'lin_api_test_key']);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'linear',
        'external_id' => 'LIN-456',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.linear.app/graphql') {
            return false;
        }

        $body = $request->data();

        return str_contains($body['query'] ?? '', 'issueUpdate')
            && str_contains($body['query'] ?? '', '"done"')
            && ($body['variables']['issueId'] ?? '') === 'LIN-456';
    });
});

/*
|--------------------------------------------------------------------------
| Slack Notification
|--------------------------------------------------------------------------
*/

test('posts summary and findings URL as Slack thread reply', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'result' => 'Analysis shows three bottlenecks',
            'session_id' => 'sess_slack',
        ])),
    ]);
    Http::fake();

    File::shouldReceive('exists')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(true);
    File::shouldReceive('size')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(1024);

    config(['yak.channels.slack.bot_token' => 'xoxb-test-token']);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'slack',
        'slack_channel' => 'C12345',
        'slack_thread_ts' => '1234567890.123456',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle();

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://slack.com/api/chat.postMessage') {
            return false;
        }

        $body = $request->data();

        return ($body['channel'] ?? '') === 'C12345'
            && ($body['thread_ts'] ?? '') === '1234567890.123456'
            && str_contains($body['text'] ?? '', 'Analysis shows three bottlenecks')
            && str_contains($body['text'] ?? '', '/artifacts/');
    });
});

/*
|--------------------------------------------------------------------------
| Error Handling
|--------------------------------------------------------------------------
*/

test('Claude error response marks task as failed', function () {
    Process::fake([
        'git checkout *' => Process::result(''),
        'git pull *' => Process::result(''),
        'claude *' => Process::result(json_encode([
            'is_error' => true,
            'result' => 'Rate limited by API',
        ])),
    ]);
    Http::fake();

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo']);

    $job = new ResearchYakJob($task);
    $job->handle();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('ResearchYakJob dispatches to yak-claude queue', function () {
    $task = YakTask::factory()->pending()->make();
    $job = new ResearchYakJob($task);

    expect($job->queue)->toBe('yak-claude');
});
