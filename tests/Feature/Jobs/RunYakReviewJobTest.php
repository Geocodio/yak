<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakReviewJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\GitHubAppService;
use App\Services\IncusSandboxManager;
use App\Services\LinearIssueFetcher;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.sandbox.workspace_path', '/workspace');
});

it('runs a full-scope review end to end', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/42',
        'external_id' => 'https://github.com/geocodio/api/pull/42',
        'branch_name' => 'feat/retry',
        'context' => json_encode([
            'pr_number' => 42,
            'head_sha' => 'abc123',
            'base_sha' => 'def456',
            'author' => 'mathias',
            'title' => 'Retry',
            'body' => 'Adds retry.',
            'review_scope' => 'full',
            'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('yak-task-' . $task->id);
    $sandbox->shouldReceive('run')->andReturn(
        Process::result(output: "app/Foo.php\n", exitCode: 0),
    );
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's-1',
        resultSummary: "Review done.\n\n```json\n{\"summary\":\"Adds retry.\",\"verdict\":\"Approve with suggestions\",\"verdict_detail\":\"ok\",\"findings\":[{\"file\":\"app/Foo.php\",\"line\":12,\"severity\":\"must_fix\",\"category\":\"Performance\",\"body\":\"Null check missing.\"}]}\n```",
        costUsd: 0.12,
        numTurns: 3,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('createPullRequestReview')->andReturn([
        'id' => 7777,
        'comments' => [
            ['id' => 111, 'path' => 'app/Foo.php', 'line' => 12, 'body' => 'Null check missing.'],
        ],
    ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(PrReview::where('yak_task_id', $task->id)->exists())->toBeTrue()
        ->and(PrReviewComment::count())->toBe(1);
});

it('does not fetch Linear ticket when no identifier is present', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/60',
        'external_id' => 'https://github.com/geocodio/api/pull/60',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 60,
            'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 'plain title', 'body' => 'no ticket here',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $fetcher = mock(LinearIssueFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    app()->instance(LinearIssueFetcher::class, $fetcher);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: "```json\n{\"summary\":\"\",\"verdict\":\"Approve\",\"verdict_detail\":\"\",\"findings\":[]}\n```",
        costUsd: 0, numTurns: 1, durationMs: 1, isError: false,
        clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('t');
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 1, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect(PrReview::where('yak_task_id', $task->id)->exists())->toBeTrue();
});

it('skips Linear fetch when no LinearOauthConnection exists', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/61',
        'external_id' => 'https://github.com/geocodio/api/pull/61',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 61,
            'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => 'Fixes GEO-99',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    // No LinearOauthConnection exists, so fetcher should never be called.
    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $fetcher = mock(LinearIssueFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    app()->instance(LinearIssueFetcher::class, $fetcher);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: "```json\n{\"summary\":\"\",\"verdict\":\"Approve\",\"verdict_detail\":\"\",\"findings\":[]}\n```",
        costUsd: 0, numTurns: 1, durationMs: 1, isError: false,
        clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('t');
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 1, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect(PrReview::where('yak_task_id', $task->id)->exists())->toBeTrue();
});
