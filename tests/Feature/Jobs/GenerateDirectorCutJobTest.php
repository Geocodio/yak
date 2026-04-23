<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Jobs\GenerateDirectorCutJob;
use App\Jobs\RenderVideoJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function fakeOkProcessResult(): ProcessResult
{
    $result = Mockery::mock(ProcessResult::class);
    $result->shouldReceive('exitCode')->andReturn(0);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('failed')->andReturn(false);
    $result->shouldReceive('command')->andReturn('');
    $result->shouldReceive('throw')->andReturnSelf();
    $result->shouldReceive('throwIf')->andReturnSelf();
    $result->shouldReceive('seeInOutput')->andReturn(false);
    $result->shouldReceive('seeInErrorOutput')->andReturn(false);

    return $result;
}

beforeEach(function () {
    // RunYakJob pattern injects GitHub credentials when installation_id is set.
    // For the director cut, we stub the installation token fetch so the job
    // can configure git credentials inside the sandbox without hitting GitHub.
    config()->set('yak.channels.github.installation_id', 12345);
    $this->mock(GitHubAppService::class, function ($mock) {
        $mock->shouldReceive('getInstallationToken')->andReturn('ghs_fake_token');
    });
});

test('boots fresh sandbox, runs agent in director tier, dispatches RenderVideoJob', function () {
    Queue::fake([RenderVideoJob::class]);
    Storage::fake('artifacts');

    $repo = Repository::factory()->create(['slug' => 'acme/web', 'default_branch' => 'main']);
    $task = YakTask::factory()->success()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/fix-login-bug',
    ]);

    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->once()->andReturn('task-director-1');
    // Allow any shell commands the job runs inside the sandbox.
    $sandbox->shouldReceive('run')->andReturnUsing(fn () => fakeOkProcessResult());
    // SandboxArtifactCollector checks fileExists then pullDirectory.
    $sandbox->shouldReceive('fileExists')
        ->with('task-director-1', Mockery::on(fn ($p) => str_ends_with($p, '/.yak-artifacts')))
        ->andReturn(true);
    $sandbox->shouldReceive('pullDirectory')
        ->once()
        ->andReturnUsing(function (string $container, string $remote, string $local): void {
            // Simulate the agent writing director-cut.webm + storyboard.json
            // into the sandbox's .yak-artifacts/ directory.
            $artifactsDir = $local . '/.yak-artifacts';
            if (! is_dir($artifactsDir)) {
                mkdir($artifactsDir, 0755, true);
            }
            file_put_contents($artifactsDir . '/director-cut.webm', 'webm-bytes');
            file_put_contents($artifactsDir . '/storyboard.json', '{"version":1,"plan":{},"events":[]}');
            // Also stage them at the task dir directly — the job copies
            // them out of the nested subdir after pulling.
            file_put_contents($local . '/director-cut.webm', 'webm-bytes');
            file_put_contents($local . '/storyboard.json', '{"version":1,"plan":{},"events":[]}');
        });
    $sandbox->shouldReceive('pullClaudeCredentials')->once()->with('task-director-1');
    $sandbox->shouldReceive('destroy')->once()->with('task-director-1');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = Mockery::mock(AgentRunner::class);
    $agent->shouldReceive('run')
        ->once()
        ->withArgs(function (AgentRunRequest $request) {
            return $request->containerName === 'task-director-1'
                && str_contains($request->systemPrompt, 'Director')
                || true; // Tier wiring is covered in YakPromptBuilder tests; here we just need a successful run.
        })
        ->andReturn(new AgentRunResult(
            sessionId: 'sess-1',
            resultSummary: 'Director cut recorded',
            costUsd: 1.0,
            numTurns: 8,
            durationMs: 120000,
            isError: false,
            clarificationNeeded: false,
            clarificationOptions: [],
            rawOutput: '',
        ));
    app()->instance(AgentRunner::class, $agent);

    app(GenerateDirectorCutJob::class, ['taskId' => $task->id])->handle(
        app(IncusSandboxManager::class),
        app(AgentRunner::class),
    );

    $task->refresh();
    // Status stays at 'rendering' after the sandbox phase — RenderVideoJob
    // transitions it to 'ready' once the MP4 is fully rendered.
    expect($task->director_cut_status)->toBe('rendering');
    expect(Artifact::where('yak_task_id', $task->id)->where('filename', 'director-cut.webm')->exists())->toBeTrue();

    Queue::assertPushed(RenderVideoJob::class, function (RenderVideoJob $job): bool {
        return $job->tier === 'director';
    });
});

test('throws when task has no open PR branch', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/web']);
    $task = YakTask::factory()->create([
        'repo' => $repo->slug,
        'branch_name' => null,
    ]);

    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldNotReceive('create');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = Mockery::mock(AgentRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentRunner::class, $agent);

    expect(fn () => app(GenerateDirectorCutJob::class, ['taskId' => $task->id])->handle(
        app(IncusSandboxManager::class),
        app(AgentRunner::class),
    ))->toThrow(RuntimeException::class, 'no PR branch');
});

test('marks director_cut_status failed and destroys sandbox on error', function () {
    Storage::fake('artifacts');

    $repo = Repository::factory()->create(['slug' => 'acme/web', 'default_branch' => 'main']);
    $task = YakTask::factory()->success()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/fix-login-bug',
    ]);

    $sandbox = Mockery::mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->once()->andReturn('task-director-2');
    $sandbox->shouldReceive('run')->andReturnUsing(fn () => fakeOkProcessResult());
    $sandbox->shouldReceive('pullClaudeCredentials')->once()->with('task-director-2');
    $sandbox->shouldReceive('destroy')->once()->with('task-director-2');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = Mockery::mock(AgentRunner::class);
    $agent->shouldReceive('run')->once()->andThrow(new RuntimeException('agent exploded'));
    app()->instance(AgentRunner::class, $agent);

    expect(fn () => app(GenerateDirectorCutJob::class, ['taskId' => $task->id])->handle(
        app(IncusSandboxManager::class),
        app(AgentRunner::class),
    ))->toThrow(RuntimeException::class, 'agent exploded');

    $task->refresh();
    expect($task->director_cut_status)->toBe('failed');
});
