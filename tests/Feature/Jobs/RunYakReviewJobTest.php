<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\IssueFetcher as LinearIssueFetcher;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ParsedReview;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakReviewJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\RepositoryRiskProfiles;
use App\Services\ReviewOutputParser;
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

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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
    // GitHub's create-review response is the review object only — it never
    // includes the inline comments, which must be fetched separately.
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 7777]);
    $github->shouldReceive('listReviewComments')->andReturn([
        ['id' => 111, 'path' => 'app/Foo.php', 'line' => 12, 'body' => 'Null check missing.'],
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

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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
        ->andReturn(['id' => 9001]);
    $github->shouldReceive('listReviewComments')->andReturn([
        ['id' => 222, 'path' => 'app/Foo.php', 'line' => 12, 'body' => 'stored'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured['comments'])->toHaveCount(1)
        ->and($captured['comments'][0]['path'])->toBe('app/Foo.php')
        ->and($captured['comments'][0]['line'])->toBe(12)
        ->and($captured['comments'][0]['body'])->toContain('NITPICK')
        ->and($captured['comments'][0]['body'])->toContain('```suggestion')
        ->and($captured['body'])->not->toContain('Nitpicks')
        ->and($captured['body'])->toContain('Request a re-review')
        ->and($captured['body'])->toContain(route('tasks.show', $task));

    $comment = PrReviewComment::first();
    expect($comment)->not->toBeNull()
        ->and($comment->severity)->toBe('consider')
        ->and($comment->is_suggestion)->toBeTrue();
});

it('passes start_line to GitHub for multi-line suggestion ranges', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/45',
        'external_id' => 'https://github.com/geocodio/api/pull/45',
        'branch_name' => 'feat/range',
        'context' => json_encode([
            'pr_number' => 45, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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
            'file' => 'tests/Foo.php', 'line' => 12, 'start_line' => 10,
            'severity' => 'should_fix', 'category' => 'Test Quality',
            'body' => "Collapse these.\n\n```suggestion\nA\nB\nC\n```",
            'suggestion_loc' => 3,
        ]],
        summary: 'Range suggestion.',
        verdict: 'Approve with suggestions',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'tests/Foo.php',
        'patch' => "@@ -10,3 +10,3 @@\n+added 10\n+added 11\n+added 12",
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $_b, $_e, $comments) use (&$captured) {
            $captured = $comments;

            return true;
        })
        ->andReturn([
            'id' => 1,
            'comments' => [
                ['id' => 333, 'path' => 'tests/Foo.php', 'line' => 12, 'body' => 'stored'],
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['line'])->toBe(12)
        ->and($captured[0]['start_line'])->toBe(10)
        ->and($captured[0]['start_side'])->toBe('RIGHT');
});

it('strips the suggestion fence when the range is much wider than the fence', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/47',
        'external_id' => 'https://github.com/geocodio/api/pull/47',
        'branch_name' => 'feat/oversize-range',
        'context' => json_encode([
            'pr_number' => 47, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

    // Range claims 24 lines (39–62), fence has 3 — accepting would delete 21 lines.
    $fenceBody = "Improve the docblock.\n\n```suggestion\n/**\n * Better docblock.\n */\n```";
    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 62, 'start_line' => 39,
            'severity' => 'consider', 'category' => 'Documentation',
            'body' => $fenceBody,
            'suggestion_loc' => 3,
        ]],
        summary: 'Oversize range.',
        verdict: 'Approve',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -36,3 +36,28 @@\n ctx36\n ctx37\n ctx38" . str_repeat("\n+added", 25),
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $_b, $_e, $comments) use (&$captured) {
            $captured = $comments;

            return true;
        })
        ->andReturn([
            'id' => 1,
            'comments' => [
                ['id' => 555, 'path' => 'app/Foo.php', 'line' => 62, 'body' => 'stored'],
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['line'])->toBe(62)
        ->and($captured[0])->not->toHaveKey('start_line')
        ->and($captured[0]['body'])->not->toContain('```suggestion')
        ->and($captured[0]['body'])->toContain('Suggestion fence omitted')
        ->and($captured[0]['body'])->toContain('Improve the docblock.');
});

it('keeps the suggestion fence for legitimate small consolidations', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/48',
        'external_id' => 'https://github.com/geocodio/api/pull/48',
        'branch_name' => 'feat/consolidate',
        'context' => json_encode([
            'pr_number' => 48, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

    // Replace 4 lines with 1 — diff of 3 is within the tolerance.
    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 13, 'start_line' => 10,
            'severity' => 'consider', 'category' => 'Clean Code',
            'body' => "Collapse to a one-liner.\n\n```suggestion\nreturn \$value ?? throw new RuntimeException();\n```",
            'suggestion_loc' => 1,
        ]],
        summary: 'Consolidate.',
        verdict: 'Approve',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,4 +10,4 @@\n+added 10\n+added 11\n+added 12\n+added 13",
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $_b, $_e, $comments) use (&$captured) {
            $captured = $comments;

            return true;
        })
        ->andReturn([
            'id' => 1,
            'comments' => [
                ['id' => 666, 'path' => 'app/Foo.php', 'line' => 13, 'body' => 'stored'],
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['start_line'])->toBe(10)
        ->and($captured[0]['line'])->toBe(13)
        ->and($captured[0]['body'])->toContain('```suggestion');
});

it('strips the suggestion fence and omits start_line when any range line is outside the diff hunk', function () {
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
        'pr_url' => 'https://github.com/geocodio/api/pull/46',
        'external_id' => 'https://github.com/geocodio/api/pull/46',
        'branch_name' => 'feat/partial-range',
        'context' => json_encode([
            'pr_number' => 46, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

    // Range claims lines 8–12, but the hunk only covers 10–14.
    fakeReviewParser(
        findings: [[
            'file' => 'tests/Foo.php', 'line' => 12, 'start_line' => 8,
            'severity' => 'consider', 'category' => 'Test Quality',
            'body' => "tweak\n\n```suggestion\nA\nB\nC\n```",
            'suggestion_loc' => 3,
        ]],
        summary: 'Partial range.',
        verdict: 'Approve',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => 'tests/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);

    $captured = null;
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $_b, $_e, $comments) use (&$captured) {
            $captured = $comments;

            return true;
        })
        ->andReturn([
            'id' => 2,
            'comments' => [
                ['id' => 444, 'path' => 'tests/Foo.php', 'line' => 12, 'body' => 'stored'],
            ],
        ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['line'])->toBe(12)
        ->and($captured[0])->not->toHaveKey('start_line')
        ->and($captured[0])->not->toHaveKey('start_side')
        ->and($captured[0]['body'])->not->toContain('```suggestion')
        ->and($captured[0]['body'])->toContain('Suggestion fence omitted');
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

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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
    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
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

it('carries the reviewed profile through a full approval and fails closed if it changes', function (bool $changeProfile) {
    $repo = Repository::factory()->create([
        'slug' => 'acme/api', 'github_full_name' => 'acme/api',
        'pr_review_enabled' => true, 'is_active' => true,
        'pr_review_policy' => ['mode' => 'enforce', 'allowed_paths' => ['docs/**'],
            'required_checks' => [['name' => 'ci', 'app_id' => 123]]],
    ]);
    $profiles = app(RepositoryRiskProfiles::class);
    $draft = $profiles->draft($repo->slug, str_repeat('a', 40), json_encode([
        'areas' => [['name' => 'Docs', 'paths' => ['docs/**'], 'symbols' => [],
            'risk' => 'low', 'rationale' => 'Docs only.', 'evidence' => ['docs/guide.md:1']]], 'unknowns' => [],
    ]));
    $profiles->approve($repo->slug, $draft['version'], 'human');
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review, 'repo' => $repo->slug,
        'pr_url' => 'https://github.com/acme/api/pull/42',
        'context' => json_encode(['pr_number' => 42, 'head_sha' => 'head', 'base_sha' => 'base',
            'base_ref' => 'main', 'author' => 'alice', 'title' => 'Docs', 'body' => '', 'review_scope' => 'full']),
    ]);
    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('yak-task-' . $task->id);
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: "docs/guide.md\n", exitCode: 0));
    app()->instance(IncusSandboxManager::class, $sandbox);
    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->once()->andReturnUsing(function ($request) use ($draft, $repo, $changeProfile) {
        expect($request->prompt)->toContain($draft['version']);
        if ($changeProfile) {
            RiskProfile::where('repo', $repo->slug)->where('version', $draft['version'])
                ->update(['approved_by' => null, 'approved_at' => null]);
        }

        return new AgentRunResult(sessionId: 'approval', resultSummary: 'review', costUsd: 0.01,
            numTurns: 1, durationMs: 10, isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '');
    });
    $signals = ['model_confidence' => 90, 'uncertainties' => [], 'human_review_reasons' => []];
    foreach (['impact' => 1, 'blast_radius' => 1, 'behavior_change' => 0, 'verification_strength' => 3, 'context_completeness' => 3] as $key => $value) {
        $signals[$key] = ['value' => $value, 'explanation' => 'Verified.', 'references' => ['docs/guide.md:1']];
    }
    $parser = mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview('Docs.', 'Approve', 'Verified.', [], risk: 'low', signals: $signals));
    app()->instance(ReviewOutputParser::class, $parser);
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listPullRequestFiles')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequest')->andReturn(['state' => 'open', 'draft' => false, 'changed_files' => 1,
        'head' => ['sha' => 'head', 'repo' => ['full_name' => 'acme/api']], 'base' => ['sha' => 'base', 'ref' => 'main'], 'user' => ['login' => 'alice']]);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('approvalEvidence')->andReturn(['dismiss_stale_reviews' => true, 'threads_clear' => true,
        'check_runs' => [['name' => 'ci', 'app' => ['id' => 123], 'status' => 'completed', 'conclusion' => 'success']],
        'total_count' => 1, 'statuses' => [], 'status_count' => 0]);
    $event = $changeProfile ? 'COMMENT' : 'APPROVE';
    $github->shouldReceive('createPullRequestReview')->once()->with(12345, 'acme/api', 42, Mockery::type('string'), $event, [], 'head')->andReturn(['id' => 123]);
    app()->instance(GitHubAppService::class, $github);
    (new RunYakReviewJob($task))->handle($agent);
    expect($task->fresh()->error_log)->toBeNull();
    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and(PrReview::where('yak_task_id', $task->id)->firstOrFail()->risk_assessment['event'])->toBe($event);
})->with([false, true]);
