<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Models\Artifact;
use App\Models\LinearOauthConnection;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/*
|--------------------------------------------------------------------------
| Successful Research
|--------------------------------------------------------------------------
*/

test('successful research transitions task to success with result_summary and completed_at', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_research_1',
        resultSummary: 'Found 3 key areas for improvement',
        costUsd: 1.25,
        numTurns: 10,
        durationMs: 90000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git pull *' => Process::result(''),
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
    $job->handle($fake);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('Found 3 key areas for improvement')
        ->and((float) $task->cost_usd)->toBe(1.25)
        ->and($task->session_id)->toBe('sess_research_1')
        ->and($task->num_turns)->toBe(10)
        ->and($task->duration_ms)->toBe(90000)
        ->and($task->completed_at)->not->toBeNull();
});

test('research creates sandbox and completes successfully', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);
    Http::fake();

    $repository = Repository::factory()->create([
        'slug' => 'test-repo',
        'path' => '/home/yak/repos/test-repo',
        'default_branch' => 'main',
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success);

    // Sandbox was created and destroyed
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);
});

test('research does not create any branch', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_1',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

    $task->refresh();
    expect($task->branch_name)->toBeNull();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'git checkout -b'));
});

/*
|--------------------------------------------------------------------------
| HTML Artifact Collection
|--------------------------------------------------------------------------
*/

test('collects HTML artifact from sandbox when present', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_artifact',
        resultSummary: 'Research complete',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    // Create a sandbox fake that reports the artifact exists and writes a real file on pull
    $fakeSandbox = new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_contains($path, 'research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            $dir = dirname($localPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($localPath, '<html>research</html>');
        }
    };
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);
    Http::fake();
    Storage::fake('artifacts');

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

    $artifact = Artifact::where('yak_task_id', $task->id)->first();

    expect($artifact)->not->toBeNull()
        ->and($artifact->type)->toBe('research')
        ->and($artifact->filename)->toBe('research.html')
        ->and($artifact->disk_path)->toBe("{$task->id}/research.html");
});

test('handles missing HTML artifact gracefully', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_no_artifact',
        resultSummary: 'Research complete',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);
    Http::fake();

    File::shouldReceive('exists')
        ->with('/home/yak/repos/test-repo/.yak-artifacts/research.html')
        ->andReturn(false);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo', 'source' => 'manual']);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

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
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_linear',
        resultSummary: 'Codebase audit complete',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    // Sandbox that reports artifact exists and writes file on pull
    $fakeSandbox = new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_contains($path, 'research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            $dir = dirname($localPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($localPath, '<html>research</html>');
        }
    };
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);
    Http::fake();
    Storage::fake('artifacts');

    LinearOauthConnection::factory()->create();

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'linear',
        'external_id' => 'LIN-123',
        'linear_agent_session_id' => 'session-research-1',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.linear.app/graphql') {
            return false;
        }

        $body = $request->data();

        if (! str_contains($body['query'] ?? '', 'agentActivityCreate')) {
            return false;
        }

        $input = $body['variables']['input'] ?? [];

        return ($input['agentSessionId'] ?? null) === 'session-research-1'
            && ($input['content']['type'] ?? null) === 'response'
            && str_contains($input['content']['body'] ?? '', 'Codebase audit complete')
            && str_contains($input['content']['body'] ?? '', '/artifacts/');
    });
});

test('moves Linear issue to Done state', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_linear_done',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);
    Http::fake();
    File::shouldReceive('exists')->andReturn(false);

    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state-uuid');

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'linear',
        'external_id' => 'LIN-456',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.linear.app/graphql') {
            return false;
        }

        $body = $request->data();

        return str_contains($body['query'] ?? '', 'issueUpdate')
            && ($body['variables']['stateId'] ?? '') === 'done-state-uuid'
            && ($body['variables']['issueId'] ?? '') === 'LIN-456';
    });
});

/*
|--------------------------------------------------------------------------
| Slack Notification
|--------------------------------------------------------------------------
*/

test('posts summary and findings URL as Slack thread reply', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_slack',
        resultSummary: 'Analysis shows three bottlenecks',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    // Sandbox that reports artifact exists and writes file on pull
    $fakeSandbox = new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_contains($path, 'research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            $dir = dirname($localPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($localPath, '<html>research</html>');
        }
    };
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);

    Process::fake(['*' => Process::result('')]);
    Http::fake();
    Storage::fake('artifacts');

    config(['yak.channels.slack.bot_token' => 'xoxb-test-token']);

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'test-repo',
        'source' => 'slack',
        'slack_channel' => 'C12345',
        'slack_thread_ts' => '1234567890.123456',
    ]);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

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
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: '',
        resultSummary: 'Rate limited by API',
        costUsd: 0.0,
        numTurns: 0,
        durationMs: 0,
        isError: true,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);

    Process::fake([
        '*git checkout *' => Process::result(''),
        '*git pull *' => Process::result(''),
    ]);
    Http::fake();

    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'test-repo']);

    $job = new ResearchYakJob($task);
    $job->handle($fake);

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
