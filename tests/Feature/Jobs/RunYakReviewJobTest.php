<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\IssueFetcher as LinearIssueFetcher;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ParsedReview;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\RepositoryRiskProfiles;
use App\Services\ReviewOutputParser;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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
    $sandbox->shouldReceive('run')
        ->withArgs(fn ($container, $command): bool => str_contains($command, 'git show'))
        ->andReturn(Process::result(output: implode("\n", array_merge(array_fill(0, 11, 'ctx'), ['    public int $count = 0;', 'ctx'])) . "\n", exitCode: 0));
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: "app/Foo.php\n", exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's-nit', resultSummary: "Rename for clarity.\n\n```suggestion\n    public int \$retryCount = 0;\n```", costUsd: 0.01, numTurns: 1, durationMs: 10,
        isError: false, rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    fakeReviewParser(
        findings: [[
            'file' => 'app/Foo.php', 'line' => 12, 'severity' => 'consider',
            'category' => 'Clean Code', 'body' => "Rename for clarity.\n\n```original\n    public int \$count = 0;\n```\n```suggestion\n    public int \$retryCount = 0;\n```",
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
        ->and($captured['comments'][0]['body'])->not->toContain('```original')
        ->and($captured['body'])->not->toContain('Nitpicks')
        ->and($captured['body'])->toContain('Request a re-review')
        ->and($captured['body'])->toContain(route('tasks.show', $task));

    $comment = PrReviewComment::first();
    expect($comment)->not->toBeNull()
        ->and($comment->severity)->toBe('consider')
        ->and($comment->is_suggestion)->toBeTrue();
});

/**
 * Run a review whose single finding carries a suggestion, with the file at
 * the PR head served from the sandbox, and return the posted line comments.
 *
 * @param  array<string, mixed>  $finding
 * @return array<int, array<string, mixed>>
 */
function postSuggestionReview(array $finding, string $fileAtHead, string $patch): array
{
    Repository::factory()->create([
        'slug' => 'geocodio/api',
        'pr_review_enabled' => true,
        'default_branch' => 'main',
        'ci_system' => 'none',
        'is_active' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/45',
        'external_id' => 'https://github.com/geocodio/api/pull/45',
        'branch_name' => 'feat/suggestion',
        'context' => json_encode([
            'pr_number' => 45, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => 't', 'body' => '',
            'review_scope' => 'full', 'incremental_base_sha' => null,
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')
        ->withArgs(fn ($container, $command): bool => str_contains($command, 'git show'))
        ->andReturn(Process::result(output: $fileAtHead, exitCode: 0));
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: "## Findings\n" . $finding['body'], costUsd: 0, numTurns: 1, durationMs: 1,
        isError: false, rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    fakeReviewParser(
        findings: [$finding + ['severity' => 'should_fix', 'category' => 'Correctness']],
        verdict: 'Approve with suggestions',
    );

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([[
        'filename' => $finding['file'],
        'patch' => $patch,
    ]]);

    $captured = [];
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $_b, $_e, $comments) use (&$captured) {
            $captured = $comments;

            return true;
        })
        ->andReturn(['id' => 1]);
    $github->shouldReceive('listReviewComments')->andReturn([
        ['id' => 333, 'path' => $finding['file'], 'line' => 1, 'body' => 'stored'],
    ]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    return $captured;
}

it('derives the multi-line range from the original fence, not the reported line', function () {
    // The reviewer reports only the last line, as in the cron/tags case
    // where a single-line anchor duplicated five lines on accept.
    $fileAtHead = "ctx1\nctx2\n- name: Schedule purge\n  cron:\n    job: 'purge'\n";

    $comments = postSuggestionReview(
        [
            'file' => 'ansible/tasks/cron.yml', 'line' => 5,
            'body' => "Add tags.\n\n```original\n- name: Schedule purge\n  cron:\n    job: 'purge'\n```\n```suggestion\n- name: Schedule purge\n  cron:\n    job: 'purge'\n  tags:\n    - deploy_web\n```",
        ],
        $fileAtHead,
        "@@ -1,2 +1,5 @@\n ctx1\n ctx2\n+- name: Schedule purge\n+  cron:\n+    job: 'purge'",
    );

    expect($comments)->toHaveCount(1)
        ->and($comments[0]['start_line'])->toBe(3)
        ->and($comments[0]['line'])->toBe(5)
        ->and($comments[0]['start_side'])->toBe('RIGHT')
        ->and($comments[0]['body'])->toContain('```suggestion')
        ->and($comments[0]['body'])->not->toContain('```original');

    expect(PrReviewComment::first()->line_number)->toBe(5)
        ->and(PrReviewComment::first()->is_suggestion)->toBeTrue();
});

it('anchors to the original lines even when the reported line is far away', function () {
    $fileAtHead = implode("\n", array_map(fn (int $n): string => "line {$n}", range(1, 70))) . "\n";

    $comments = postSuggestionReview(
        [
            'file' => 'app/Foo.php', 'line' => 62,
            'body' => "Improve the docblock.\n\n```original\nline 40\nline 41\n```\n```suggestion\n/**\n * Better docblock.\n */\n```",
        ],
        $fileAtHead,
        "@@ -36,3 +36,28 @@\n ctx36\n ctx37\n ctx38" . str_repeat("\n+added", 25),
    );

    expect($comments[0]['start_line'])->toBe(40)
        ->and($comments[0]['line'])->toBe(41)
        ->and($comments[0]['body'])->toContain('```suggestion');
});

it('posts a consolidation that replaces several lines with one', function () {
    $fileAtHead = implode("\n", array_merge(array_fill(0, 9, 'ctx'), ['if ($value === null) {', '    throw new RuntimeException();', '}', 'return $value;'])) . "\n";

    $comments = postSuggestionReview(
        [
            'file' => 'app/Foo.php', 'line' => 13,
            'body' => "Collapse to a one-liner.\n\n```original\nif (\$value === null) {\n    throw new RuntimeException();\n}\nreturn \$value;\n```\n```suggestion\nreturn \$value ?? throw new RuntimeException();\n```",
        ],
        $fileAtHead,
        "@@ -8,6 +8,6 @@\n ctx\n ctx\n+if (\$value === null) {\n+    throw new RuntimeException();\n+}\n+return \$value;",
    );

    expect($comments[0]['start_line'])->toBe(10)
        ->and($comments[0]['line'])->toBe(13)
        ->and($comments[0]['body'])->toContain('```suggestion');
});

it('strips the suggestion fence when any original line is outside the diff hunk', function () {
    $fileAtHead = implode("\n", array_map(fn (int $n): string => "line {$n}", range(1, 14))) . "\n";

    $comments = postSuggestionReview(
        [
            'file' => 'tests/Foo.php', 'line' => 12,
            'body' => "tweak\n\n```original\nline 8\nline 9\nline 10\n```\n```suggestion\nA\nB\nC\n```",
        ],
        $fileAtHead,
        "@@ -10,5 +10,5 @@\n line 10\n line 11\n+line 12\n line 13\n line 14",
    );

    expect($comments)->toHaveCount(1)
        ->and($comments[0]['line'])->toBe(12)
        ->and($comments[0])->not->toHaveKey('start_line')
        ->and($comments[0]['body'])->not->toContain('```suggestion')
        ->and($comments[0]['body'])->not->toContain('```original')
        ->and($comments[0]['body'])->toContain('Suggested change omitted');

    expect(PrReviewComment::first()->is_suggestion)->toBeFalse();
});

it('strips a suggestion fence that has no original fence', function () {
    $comments = postSuggestionReview(
        [
            'file' => 'tests/Foo.php', 'line' => 12,
            'body' => "tweak\n\n```suggestion\nA\nB\nC\n```",
        ],
        "line 1\n",
        "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    );

    expect($comments[0]['line'])->toBe(12)
        ->and($comments[0]['body'])->not->toContain('```suggestion')
        ->and($comments[0]['body'])->toContain('Suggested change omitted');
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
        isError: false, rawOutput: '',
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
        isError: false, rawOutput: '',
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
        isError: false, rawOutput: '',
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
        isError: false, rawOutput: '',
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
            numTurns: 1, durationMs: 10, isError: false, rawOutput: '');
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

it('reports a review on a repository with review turned off as a failure', function () {
    Queue::fake([SendNotificationJob::class]);
    Repository::factory()->create(['slug' => 'geocodio/api', 'pr_review_enabled' => false]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'source' => 'github',
        'repo' => 'geocodio/api',
        'pr_url' => 'https://github.com/geocodio/api/pull/42',
        'context' => json_encode(['pr_number' => 42]),
    ]);

    (new RunYakReviewJob($task))->handle(mock(AgentRunner::class));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $notification): bool => $notification->type === NotificationType::Error
        && $notification->message === 'Repository missing or PR review not enabled');
});
