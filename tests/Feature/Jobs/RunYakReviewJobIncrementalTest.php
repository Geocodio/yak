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

it('computes diff against incremental_base_sha in incremental scope', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/50',
        'external_id' => 'https://github.com/geocodio/api/pull/50',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 50,
            'head_sha' => 'new-head',
            'base_sha' => 'pr-base',
            'author' => 'mathias',
            'title' => 't',
            'body' => 'b',
            'review_scope' => 'incremental',
            'incremental_base_sha' => 'old-head',
        ]),
    ]);

    $capturedCommands = [];
    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('container');
    $sandbox->shouldReceive('run')->andReturnUsing(function ($name, $cmd) use (&$capturedCommands) {
        $capturedCommands[] = $cmd;

        return Process::result(output: "app/Foo.php\n", exitCode: 0);
    });
    $sandbox->shouldReceive('pullClaudeCredentials');
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's',
        resultSummary: 'prose review',
        costUsd: 0.01, numTurns: 1, durationMs: 100,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 5, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    $diffCommands = array_filter($capturedCommands, fn ($c) => str_contains($c, 'git diff'));
    $commandsText = implode("\n", $diffCommands);

    expect($commandsText)->toContain("'old-head'")
        ->and($commandsText)->not->toContain("'pr-base'");
});

it('falls back to full review when incremental base fetch fails', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/51',
        'external_id' => 'https://github.com/geocodio/api/pull/51',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 51,
            'head_sha' => 'new-head',
            'base_sha' => 'pr-base',
            'author' => 'mathias',
            'title' => 't',
            'body' => 'b',
            'review_scope' => 'incremental',
            'incremental_base_sha' => 'missing-sha',
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('container');
    $sandbox->shouldReceive('run')->andReturnUsing(function ($name, $cmd) {
        if (str_contains($cmd, 'git fetch origin ') && str_contains($cmd, 'missing-sha')) {
            return Process::result(output: '', errorOutput: 'fatal: no such ref', exitCode: 1);
        }

        return Process::result(output: "app/Foo.php\n", exitCode: 0);
    });
    $sandbox->shouldReceive('pullClaudeCredentials');
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's',
        resultSummary: 'prose review',
        costUsd: 0.01, numTurns: 1, durationMs: 100,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 5, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    $review = PrReview::where('yak_task_id', $task->id)->first();
    expect($review->review_scope)->toBe('full')
        ->and($review->incremental_base_sha)->toBeNull();
});
