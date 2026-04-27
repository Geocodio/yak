<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Jobs\RunYakReviewJob;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.sandbox.workspace_path', '/workspace');
});

function setUpReviewJobMocks(string $scope = 'full'): YakTask
{
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'ci_system' => 'none',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/99',
        'external_id' => 'https://github.com/geocodio/api/pull/99',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 99,
            'head_sha' => 'new-head',
            'base_sha' => 'base',
            'author' => 'mathias',
            'title' => 't',
            'body' => 'b',
            'review_scope' => $scope,
            'incremental_base_sha' => $scope === 'incremental' ? 'old-head' : null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('yak-task-' . $task->id);
    $sandbox->shouldReceive('run')->andReturn(
        Process::result(output: "app/Foo.php\n", exitCode: 0),
    );
    $sandbox->shouldReceive('pullClaudeCredentials');
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's-1',
        resultSummary: 'prose review',
        costUsd: 0.01,
        numTurns: 1,
        durationMs: 100,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    return $task;
}

it('dismisses prior non-dismissed reviews on a full-scope run', function () {
    $task = setUpReviewJobMocks('full');

    $priorTask = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => $task->pr_url,
    ]);

    $prior = PrReview::factory()->create([
        'yak_task_id' => $priorTask->id,
        'repo' => 'geocodio/api',
        'pr_number' => 99,
        'pr_url' => $task->pr_url,
        'github_review_id' => 1234,
        'commit_sha_reviewed' => 'old-head',
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn([
        'id' => 5678,
        'comments' => [],
    ]);
    $github->shouldReceive('dismissPullRequestReview')
        ->once()
        ->with(12345, 'geocodio/api', 99, 1234, 'Superseded by newer commits');
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect($prior->fresh()->dismissed_at)->not->toBeNull();
});

it('does NOT dismiss prior reviews on an incremental-scope run', function () {
    $task = setUpReviewJobMocks('incremental');

    $priorTask = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => $task->pr_url,
    ]);

    $prior = PrReview::factory()->create([
        'yak_task_id' => $priorTask->id,
        'repo' => 'geocodio/api',
        'pr_number' => 99,
        'pr_url' => $task->pr_url,
        'github_review_id' => 1234,
        'commit_sha_reviewed' => 'old-head',
    ]);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn([
        'id' => 5678,
        'comments' => [],
    ]);
    $github->shouldNotReceive('dismissPullRequestReview');
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect($prior->fresh()->dismissed_at)->toBeNull();
});
