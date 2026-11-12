<?php

use App\Ai\Agents\ConcernClassifier;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\IssueFetcher as LinearIssueFetcher;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\DataTransferObjects\ReviewFinding;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\DismissYakApprovalJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\ReviewOutputParser;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;

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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([[
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([[
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
        ->and($captured['body'])->not->toContain('Yak will look again.')
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([[
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([[
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
    $fallbackBody = null;
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([[
        'filename' => 'app/Foo.php',
        'patch' => "@@ -10,5 +10,5 @@\n ctx10\n ctx11\n+added 12\n ctx13\n ctx14",
    ]]);
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(fn ($_i, $_r, $_p, $_b, $_e, $comments) => count($comments) === 1)
        ->andThrow(new RuntimeException('GitHub rejected line comments (422)'));
    $github->shouldReceive('createPullRequestReview')
        ->once()
        ->withArgs(function ($_i, $_r, $_p, $body, $_e, $comments) use (&$fallbackBody) {
            $fallbackBody = $body;

            return $comments === [];
        })
        ->andReturn(['id' => 42, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    $review = PrReview::where('yak_task_id', $task->id)->first();
    expect($review)->not->toBeNull()
        ->and($fallbackBody)->not->toContain('Yak will look again.')
        ->and($review->risk_assessment['findings_count'])->toBe(1)
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
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
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'app/Foo.php', 'patch' => "@@ -10,5 +10,10 @@\n context\n+added"]]);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 1, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect(PrReview::where('yak_task_id', $task->id)->exists())->toBeTrue();
});

it('records the level-based decision and approves a clean docs change at level 2', function () {
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
    Ai::fakeAgent(ConcernClassifier::class, array_fill(0, 2, ['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => false]));
    $repo = Repository::factory()->create([
        'slug' => 'acme/api', 'github_full_name' => 'acme/api',
        'pr_review_enabled' => true, 'is_active' => true,
    ]);
    $repo->setApproval(2, 'enforce', null);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
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
    $agent->shouldReceive('run')->once()->andReturn(new AgentRunResult(sessionId: 'approval', resultSummary: 'review', costUsd: 0.01,
        numTurns: 1, durationMs: 10, isError: false, rawOutput: ''));
    $parser = mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview('Docs.', 'Approve', 'Verified.', [], risk: 'low'));
    app()->instance(ReviewOutputParser::class, $parser);
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequestOrFail')->andReturn(['state' => 'open', 'draft' => false, 'changed_files' => 1,
        'head' => ['sha' => 'head', 'repo' => ['full_name' => 'acme/api']], 'base' => ['sha' => 'base', 'ref' => 'main', 'repo' => ['full_name' => 'acme/api']], 'user' => ['login' => 'alice']]);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('hasRequiredStatusChecks')->andReturn(true);
    $github->shouldReceive('createPullRequestReview')->once()
        ->with(12345, 'acme/api', 42, Mockery::on(fn (string $body): bool => str_contains($body, 'Mode: enforce. DEFCON 2. Decision: APPROVE.')
            && str_contains($body, 'Approval is not merge authorization.')), 'APPROVE', [], 'head')
        ->andReturn(['id' => 123]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($task->fresh()->error_log)->toBeNull();
    $review = PrReview::where('yak_task_id', $task->id)->firstOrFail();
    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($review->risk_assessment['event'])->toBe('APPROVE')
        ->and($review->risk_assessment['level'])->toBe(2)
        ->and($review->risk_assessment['classification']['change_kind'])->toBe('no_runtime_change')
        ->and($review->risk_assessment['head_sha'])->toBe('head');
});

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

function approvalHardeningSetup(): array
{
    config(['ai.providers.anthropic.key' => 'sk-ant-test']);
    Ai::fakeAgent(ConcernClassifier::class, array_fill(0, 2, ['touched_concerns' => [], 'change_kind' => 'no_runtime_change', 'is_uncertain' => false]));
    $repo = Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'pr_review_enabled' => true, 'is_active' => true]);
    $repo->setApproval(2, 'enforce', null);
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review, 'repo' => $repo->slug, 'pr_url' => 'https://github.com/acme/api/pull/42',
        'context' => json_encode(['pr_number' => 42, 'head_sha' => 'head', 'base_sha' => 'base',
            'base_ref' => 'main', 'author' => 'alice', 'title' => 'Docs', 'body' => '', 'review_scope' => 'full']),
    ]);
    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('yak-task-' . $task->id);
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: "docs/guide.md\n", exitCode: 0));
    app()->instance(IncusSandboxManager::class, $sandbox);
    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->once()->andReturn(new AgentRunResult(sessionId: 'a', resultSummary: 'review', costUsd: 0.01,
        numTurns: 1, durationMs: 10, isError: false, rawOutput: ''));
    $parser = mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview('Docs.', 'Approve', 'Verified.', [], risk: 'low'));
    app()->instance(ReviewOutputParser::class, $parser);
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $github->shouldReceive('hasRequiredStatusChecks')->andReturn(true);

    return [$task, $agent, $github];
}

function approvalLivePullRequest(string $headSha): array
{
    return ['state' => 'open', 'draft' => false, 'changed_files' => 1, 'user' => ['login' => 'alice'],
        'head' => ['sha' => $headSha, 'repo' => ['full_name' => 'acme/api']], 'base' => ['ref' => 'main', 'repo' => ['full_name' => 'acme/api']]];
}

it('dismisses an approval when new commits arrive while it is being submitted', function () {
    [$task, $agent, $github] = approvalHardeningSetup();
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequestOrFail')->andReturn(approvalLivePullRequest('head'), approvalLivePullRequest('newer'));
    $github->shouldReceive('createPullRequestReview')->once()->withArgs(fn ($i, $r, $n, $b, $event) => $event === 'APPROVE')->andReturn(['id' => 123]);
    $github->shouldReceive('dismissPullRequestReviewOrFail')->once()
        ->with(12345, 'acme/api', 42, 123, 'New commits arrived while Yak was approving. Reviewing again.');
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect(PrReview::where('yak_task_id', $task->id)->firstOrFail()->dismissed_at)->not->toBeNull();
});

it('leaves dismissed_at null and queues a retry when the stale approval cannot be dismissed', function () {
    Queue::fake();
    [$task, $agent, $github] = approvalHardeningSetup();
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequestOrFail')->andReturn(approvalLivePullRequest('head'), approvalLivePullRequest('newer'));
    $github->shouldReceive('createPullRequestReview')->once()->withArgs(fn ($i, $r, $n, $b, $event) => $event === 'APPROVE')->andReturn(['id' => 123]);
    $github->shouldReceive('dismissPullRequestReviewOrFail')->once()->andThrow(new RuntimeException('422'));
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    $review = PrReview::where('yak_task_id', $task->id)->firstOrFail();
    expect($review->dismissed_at)->toBeNull();
    Queue::assertPushed(DismissYakApprovalJob::class, fn (DismissYakApprovalJob $job): bool => $job->prReviewId === $review->id);
});

it('never approves when the pull request file list cannot be loaded completely', function () {
    [$task, $agent, $github] = approvalHardeningSetup();
    $github->shouldReceive('listPullRequestFilesOrFail')->andThrow(new RuntimeException('502'));
    $github->shouldReceive('getPullRequestOrFail')->andReturn(approvalLivePullRequest('head'));
    $github->shouldReceive('createPullRequestReview')->once()->withArgs(fn ($i, $r, $n, $b, $event) => $event === 'COMMENT')->andReturn(['id' => 123]);
    $github->shouldNotReceive('dismissPullRequestReview');
    $github->shouldNotReceive('dismissPullRequestReviewOrFail');
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect(PrReview::where('yak_task_id', $task->id)->firstOrFail()->risk_assessment['candidate'])->not->toBe('APPROVE');
});

function runLevelTwoReviewWithPriorFinding(string $status): array
{
    [$task, $agent, $github] = approvalHardeningSetup();
    $previous = PrReview::factory()->create([
        'yak_task_id' => YakTask::factory()->create(['mode' => TaskMode::Review]),
        'repo' => 'acme/api', 'pr_number' => 42, 'pr_url' => $task->pr_url, 'github_review_id' => 11,
        'dismissed_at' => now(),
    ]);
    PrReviewComment::factory()->create(['pr_review_id' => $previous->id, 'github_comment_id' => 777]);
    $parser = mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview('Docs.', 'Approve', 'Verified.', [],
        priorFindings: [new ParsedPriorFinding(777, $status, 'reply')], risk: 'low'));
    app()->instance(ReviewOutputParser::class, $parser);
    $github->shouldReceive('listReviewThreads')->once()->andReturn([]);
    $github->shouldReceive('replyToReviewComment')->andReturn(['id' => 5]);
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequestOrFail')->andReturn(approvalLivePullRequest('head'));
    $event = null;
    $github->shouldReceive('createPullRequestReview')->once()->withArgs(function ($i, $r, $n, $b, $e) use (&$event) {
        $event = $e;

        return true;
    })->andReturn(['id' => 123]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    return [$event, PrReview::where('yak_task_id', $task->id)->firstOrFail()];
}

it('holds a level 2 full re-review while an earlier finding is still outstanding', function () {
    [$event, $review] = runLevelTwoReviewWithPriorFinding(ParsedPriorFinding::STATUS_STILL_OUTSTANDING);

    expect($event)->toBe('COMMENT')
        ->and($review->risk_assessment['reasons'])->toContain('An earlier finding is still open.');
});

it('approves a level 2 full re-review whose earlier findings are fixed', function () {
    [$event] = runLevelTwoReviewWithPriorFinding(ParsedPriorFinding::STATUS_FIXED);

    expect($event)->toBe('APPROVE');
});

it('adds the second round hint only at level 2 and up', function () {
    [$task, $agent, $github] = approvalHardeningSetup();
    $parser = mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview('Docs.', 'Approve', 'Verified.',
        [new ReviewFinding('docs/guide.md', 1, 'should_fix', 'Docs', 'Fix it.')], risk: 'low'));
    app()->instance(ReviewOutputParser::class, $parser);
    $github->shouldReceive('listReviewThreads')->andReturn([]);
    $github->shouldReceive('listPullRequestFilesOrFail')->andReturn([['filename' => 'docs/guide.md', 'status' => 'added', 'patch' => "@@ -0,0 +1 @@\n+Docs"]]);
    $github->shouldReceive('getPullRequestOrFail')->andReturn(approvalLivePullRequest('head'));
    $body = null;
    $github->shouldReceive('createPullRequestReview')->once()->withArgs(function ($i, $r, $n, $b) use (&$body) {
        $body = $b;

        return true;
    })->andReturn(['id' => 123]);
    $github->shouldReceive('listReviewComments')->andReturn([]);
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle($agent);

    expect($body)->toContain('Yak will look again.');
});
