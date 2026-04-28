<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ParsedPriorFinding;
use App\DataTransferObjects\ParsedReview;
use App\Enums\TaskMode;
use App\Jobs\RunYakReviewJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\ReviewOutputParser;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 12345);
    config()->set('yak.sandbox.workspace_path', '/workspace');
});

function bootIncrementalScenario(callable $configureGithub): array
{
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api', 'pr_review_enabled' => true,
        'default_branch' => 'main', 'is_active' => true,
    ]);
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review, 'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/60',
        'external_id' => 'https://github.com/geocodio/api/pull/60',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 60, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => '', 'body' => '',
            'review_scope' => 'incremental', 'incremental_base_sha' => 'old',
        ]),
    ]);
    $priorReview = PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'pr_url' => 'https://github.com/geocodio/api/pull/60',
        'commit_sha_reviewed' => 'old',
    ]);
    $fixedComment = PrReviewComment::factory()->create([
        'pr_review_id' => $priorReview->id,
        'github_comment_id' => 1001,
        'file_path' => 'app/Foo.php', 'line_number' => 5,
        'severity' => 'must_fix', 'category' => 'Performance',
        'body' => 'broken',
    ]);
    $stillComment = PrReviewComment::factory()->create([
        'pr_review_id' => $priorReview->id,
        'github_comment_id' => 1002,
        'file_path' => 'app/Bar.php', 'line_number' => 9,
        'severity' => 'should_fix', 'category' => 'Simplicity',
        'body' => 'meh',
    ]);
    $untouchedComment = PrReviewComment::factory()->create([
        'pr_review_id' => $priorReview->id,
        'github_comment_id' => 1003,
        'file_path' => 'app/Baz.php', 'line_number' => 1,
        'severity' => 'consider', 'category' => 'Code Style',
        'body' => 'nit',
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('container');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: "app/Foo.php\napp/Bar.php\n", exitCode: 0));
    $sandbox->shouldReceive('pullClaudeCredentials');
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    $parser = Mockery::mock(ReviewOutputParser::class);
    $parser->shouldReceive('parse')->andReturn(new ParsedReview(
        summary: 'New revision summary.',
        verdict: 'Approve',
        verdictDetail: 'Prior fixes look good.',
        findings: [],
        priorFindings: [
            new ParsedPriorFinding(1001, 'fixed', 'Fixed in c0ffee1.'),
            new ParsedPriorFinding(1002, 'still_outstanding', 'Still busted on line 9.'),
            new ParsedPriorFinding(1003, 'untouched', ''),
        ],
    ));
    app()->instance(ReviewOutputParser::class, $parser);

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'prose', costUsd: 0,
        numTurns: 1, durationMs: 100, isError: false,
        clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([
        ['filename' => 'app/Foo.php', 'patch' => "@@ -5,1 +5,1 @@\n-old\n+new"],
        ['filename' => 'app/Bar.php', 'patch' => "@@ -9,1 +9,1 @@\n-old\n+new"],
    ]);
    $github->shouldReceive('listReviewThreads')->andReturn([
        ['thread_id' => 'A', 'is_resolved' => false, 'comment_database_ids' => [1001]],
        ['thread_id' => 'B', 'is_resolved' => false, 'comment_database_ids' => [1002]],
        ['thread_id' => 'C', 'is_resolved' => false, 'comment_database_ids' => [1003]],
    ]);
    $configureGithub($github);
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 9, 'comments' => []]);
    app()->instance(GitHubAppService::class, $github);

    return compact('task', 'fixedComment', 'stillComment', 'untouchedComment');
}

it('replies to fixed and still_outstanding threads, skips untouched', function () {
    $repliedTo = [];
    [$task, $fixed, $still, $untouched] = array_values(bootIncrementalScenario(function ($github) use (&$repliedTo) {
        $github->shouldReceive('replyToReviewComment')
            ->andReturnUsing(function ($_id, $_slug, $_pr, $commentId, $body) use (&$repliedTo) {
                $repliedTo[$commentId] = $body;

                return ['id' => 70_000 + $commentId];
            });
    }));

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect($repliedTo)->toHaveKey(1001)
        ->and($repliedTo[1001])->toBe('Fixed in c0ffee1.')
        ->and($repliedTo)->toHaveKey(1002)
        ->and($repliedTo)->not->toHaveKey(1003);

    expect($fixed->fresh()->resolution_status)->toBe('fixed')
        ->and($fixed->fresh()->resolution_reply_github_id)->toBe(70_000 + 1001)
        ->and($still->fresh()->resolution_status)->toBe('still_outstanding')
        ->and($untouched->fresh()->resolution_status)->toBe('untouched')
        ->and($untouched->fresh()->resolution_reply_github_id)->toBeNull();
});

it('prepends the rollup line to the new review body', function () {
    $capturedBody = null;
    [$task] = array_values(bootIncrementalScenario(function ($github) use (&$capturedBody) {
        $github->shouldReceive('replyToReviewComment')->andReturn(['id' => 70_000]);
        $github->shouldReceive('createPullRequestReview')
            ->andReturnUsing(function ($_id, $_slug, $_pr, $body) use (&$capturedBody) {
                $capturedBody = $body;

                return ['id' => 9, 'comments' => []];
            });
    }));

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect($capturedBody)->toStartWith(
        '**Status of prior findings:** 1 fixed, 1 still outstanding, 1 untouched.',
    );
});

it('skips replying when resolution_reply_github_id is already set (idempotent retry)', function () {
    $callCount = 0;
    [$task] = array_values(bootIncrementalScenario(function ($github) use (&$callCount) {
        $github->shouldReceive('replyToReviewComment')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;

                return ['id' => 70_000 + $callCount];
            });
    }));

    PrReviewComment::where('github_comment_id', 1001)->update([
        'resolution_reply_github_id' => 99,
        'resolution_status' => 'fixed',
    ]);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    // Only the still_outstanding finding (1002) should have triggered a reply
    expect($callCount)->toBe(1);
});

it('falls back to today\'s incremental flow when GraphQL fetch fails', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api', 'pr_review_enabled' => true,
        'default_branch' => 'main', 'is_active' => true,
    ]);
    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review, 'repo' => $repo->slug,
        'pr_url' => 'https://github.com/geocodio/api/pull/61',
        'external_id' => 'https://github.com/geocodio/api/pull/61',
        'branch_name' => 'feat/x',
        'context' => json_encode([
            'pr_number' => 61, 'head_sha' => 'h', 'base_sha' => 'b',
            'author' => 'm', 'title' => '', 'body' => '',
            'review_scope' => 'incremental', 'incremental_base_sha' => 'old',
        ]),
    ]);

    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('container');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: '', exitCode: 0));
    $sandbox->shouldReceive('pullClaudeCredentials');
    $sandbox->shouldReceive('destroy');
    app()->instance(IncusSandboxManager::class, $sandbox);

    fakeReviewParser();

    $agent = mock(AgentRunner::class);
    $agent->shouldReceive('run')->andReturn(new AgentRunResult(
        sessionId: 's', resultSummary: 'prose', costUsd: 0,
        numTurns: 1, durationMs: 100, isError: false,
        clarificationNeeded: false, clarificationOptions: [], rawOutput: '',
    ));
    app()->instance(AgentRunner::class, $agent);

    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getInstallationToken')->andReturn('tok');
    $github->shouldReceive('listPullRequestFiles')->andReturn([]);
    $github->shouldReceive('listReviewThreads')->andThrow(new \RuntimeException('graphql exploded'));
    $github->shouldReceive('createPullRequestReview')->andReturn(['id' => 9, 'comments' => []]);
    $github->shouldNotReceive('replyToReviewComment');
    app()->instance(GitHubAppService::class, $github);

    (new RunYakReviewJob($task))->handle(app(AgentRunner::class));

    expect($task->fresh()->status)->toBe(\App\Enums\TaskStatus::Success);
});
