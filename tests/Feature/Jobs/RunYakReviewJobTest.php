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
        resultSummary: 'Prose review from the sandboxed agent.',
        costUsd: 0.12,
        numTurns: 3,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 12, 'severity' => 'must_fix',
            'category' => 'Performance', 'body' => 'Null check missing.',
        ]],
        summary: 'Adds retry.',
        verdict: 'Approve with suggestions',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);
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

it('posts consider-severity findings as inline NITPICK comments when they sit inside the diff', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/43',
        'external_id' => 'https://github.com/geocodio/api/pull/43',
        'branch_name' => 'feat/nits',
        'context' => json_encode([
            'pr_number' => 43,
            'head_sha' => 'abc123',
            'base_sha' => 'def456',
            'author' => 'mathias',
            'title' => 'Nits',
            'body' => 'Cleanup.',
            'review_scope' => 'full',
            'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('yak-task-' . $task->id);
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: "app/Foo.php\n", exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's-nit', resultSummary: 'prose', costUsd: 0.01, numTurns: 1, durationMs: 10,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 12, 'severity' => 'consider',
            'category' => 'Clean Code', 'body' => "Rename for clarity.\n\n```suggestion\n    public int \$retryCount = 0;\n```",
            'suggestion_loc' => 1,
        ]],
        summary: 'Small cleanup PR.',
        verdict: 'Approve',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $body, $_e, $comments) use (&$captured) {
            $captured = ['body' => $body, 'comments' => $comments];

            return true;
        })
        ->andReturn([
            'id' => 9001,
            'comments' => [
                ['id' => 222, 'path' => 'app/Foo.php', 'line' => 12, 'body' => 'stored'],
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured['comments'])->toHaveCount(1)
        ->and($captured['comments'][0]['path'])->toBe('app/Foo.php')
        ->and($captured['comments'][0]['line'])->toBe(12)
        ->and($captured['comments'][0]['body'])->toContain('NITPICK')
        ->and($captured['comments'][0]['body'])->toContain('```suggestion')
        ->and($captured['body'])->not->toContain('<details>');

    $comment = PrReviewComment::first();
    expect($comment)->not->toBeNull()
        ->and($comment->severity)->toBe('consider')
        ->and($comment->is_suggestion)->toBeTrue();
});

it('keeps out-of-diff consider findings in the collapsed nitpicks block', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/44',
        'external_id' => 'https://github.com/geocodio/api/pull/44',
        'branch_name' => 'feat/oob',
        'context' => json_encode([
            'pr_number' => 44, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'p', costUsd: 0, numTurns: 1, durationMs: 1,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 999, 'severity' => 'consider',
            'category' => 'Clean Code', 'body' => 'Line is not inside any diff hunk.',
        ]],
        summary: 'Out-of-diff nit.',
        verdict: 'Approve',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $body, $_e, $comments) use (&$captured) {
            $captured = ['body' => $body, 'comments' => $comments];

            return true;
        })
        ->andReturn(['id' => 1, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured['comments'])->toBe([])
        ->and($captured['body'])->toContain('<details>')
        ->and($captured['body'])->toContain('Nitpicks (1)');
});

it('falls back to a body-only review when GitHub rejects the line comments', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/99',
        'external_id' => 'https://github.com/geocodio/api/pull/99',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 99, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class);
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 12, 'severity' => 'must_fix',
            'category' => 'Performance', 'body' => 'Commentable line.',
        ]],
        summary: 'Review with a commentable finding.',
        verdict: 'Request changes',
    );

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'prose', costUsd: 0, numTurns: 1, durationMs: 1,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    // Simulate GitHub still 422'ing even though the line is inside the diff
    // — e.g. phantom hunk header shifts. The fallback should kick in and
    // re-post with no line comments.
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(fn ($_i, $_r, $_p, $_b, $_e, $comments) => count($comments) === 1)
        ->andThrow(new RuntimeException('GitHub rejected line comments (422)'));
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(fn ($_i, $_r, $_p, $_b, $_e, $comments) => $comments === [])
        ->andReturn(['id' => 42, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    $review = PrReview::where('yak_task_id', $task->id)->first();
    expect($review)->not->toBeNull()
        ->and($review->github_review_id)->toBe(42)
        ->and($task->fresh()->status)->toBe(TaskStatus::Success);
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

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'prose review', costUsd: 0, numTurns: 1, durationMs: 1,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('t');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
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

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'prose review', costUsd: 0, numTurns: 1, durationMs: 1,
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('t');
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 1, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect(PrReview::where('yak_task_id', $task->id)->exists())->toBeTrue();
});
