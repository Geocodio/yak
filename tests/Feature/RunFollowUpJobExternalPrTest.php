<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

beforeEach(function () {
    Queue::fake();
    Process::fake(['*' => Process::result('')]);
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 201)]);
    config()->set('yak.channels.github.installation_id', 99);
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 't', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'ci_system' => 'github_actions', 'default_branch' => 'main']);
});

function externalTask(): YakTask
{
    return YakTask::factory()->create([
        'repo' => 'web', 'mode' => TaskMode::Fix, 'status' => TaskStatus::Pending,
        'branch_name' => 'feature/warnings', 'session_id' => null,
        'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9,
        'targets_external_pr' => true, 'summon_review_comment_id' => 4107,
        'description' => 'fix it',
    ]);
}

function runExternal(YakTask $task, FakeSandboxManager $sandbox, string $summary): void
{
    $agent = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess', resultSummary: $summary, costUsd: 0.01, numTurns: 1, durationMs: 1000,
        isError: false, rawOutput: '{}',
    ));
    app()->instance(AgentRunner::class, $agent);
    app()->instance(IncusSandboxManager::class, $sandbox);

    (new RunFollowUpJob($task))->handle($agent);
}

it('pushes without force or rebase when the branch has not moved, and links the commit', function () {
    $sandbox = (new FakeSandboxManager)->setHeadSha(str_repeat('a1', 20));
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nScoped `_warnings` to the status schema.\n\n## PR description\n\nRewritten description.");

    expect($sandbox->commandsMatching('git reset --hard HEAD && git clean -fd'))->toHaveCount(1)
        ->and($sandbox->commandsMatching("git fetch origin 'feature/warnings'"))->not->toBe([])
        ->and($sandbox->commandsMatching('git rebase'))->toBe([])
        ->and($sandbox->commandsMatching("git push origin 'HEAD:feature/warnings'"))->toHaveCount(1)
        ->and($sandbox->commandsMatching('--force'))->toBe([]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_body_update)->toBeNull();

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies')
        && str_contains($request['body'], 'Scoped `_warnings`')
        && str_contains($request['body'], 'Pushed a1a1a1a: https://github.com/acme/web/commit/' . str_repeat('a1', 20)));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

it('rebases onto the moved branch and pushes', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('merge-base --is-ancestor');
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($sandbox->commandsMatching("git rebase 'origin/feature/warnings'"))->toHaveCount(1)
        ->and($sandbox->commandsMatching("git push origin 'HEAD:feature/warnings'"))->toHaveCount(1)
        ->and($task->fresh()->status)->toBe(TaskStatus::Success);
});

it('aborts the rebase, replies, and fails when the branch moved and the rebase conflicts', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('merge-base --is-ancestor')->failCommand("git rebase 'origin", 'CONFLICT');
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($sandbox->commandsMatching('git rebase --abort'))->toHaveCount(1)
        ->and($sandbox->commandsMatching('git push'))->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['body'], 'changed while I was working')
        && ! str_contains((string) $request['body'], 'CONFLICT'));
});

it('fails with a crafted reply when the push is rejected', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('git push', '! [rejected] raw stderr');
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['body'], 'GitHub rejected the push')
        && ! str_contains((string) $request['body'], 'raw stderr'));
});

it('keeps raw exception text off the PR', function () {
    $sandbox = new class extends FakeSandboxManager
    {
        public function injectGitCredentials(string $containerName): void
        {
            throw new RuntimeException('git config credential.helper password=secret');
        }
    };
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->toContain('password=secret');
    Http::assertSent(fn (Request $request): bool => str_starts_with((string) $request['body'], "I couldn't finish this. The details are on the task page."));
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['body'], 'secret'));
});

it('does not send a second error notification on a Claude auth failure', function () {
    $agent = new class extends FakeAgentRunner
    {
        public function run(AgentRunRequest $request): AgentRunResult
        {
            throw new ClaudeAuthException('Claude is signed out');
        }
    };
    app()->instance(AgentRunner::class, $agent);
    app()->instance(IncusSandboxManager::class, new FakeSandboxManager);
    $task = externalTask();

    (new RunFollowUpJob($task))->handle($agent);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNotPushed(SendNotificationJob::class);
    Http::assertSentCount(1);
});

it('replies with the answer when the agent made no commits', function () {
    $sandbox = (new FakeSandboxManager)->setCommitCount(0);
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nNo change needed: the schema is already split.");

    expect($sandbox->commandsMatching('git push'))->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Success);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['body'], 'No change needed'));
});

it('quotes the conversation comment it answers, since it has no thread', function () {
    $sandbox = (new FakeSandboxManager)->setCommitCount(0);
    $task = externalTask();
    $task->update(['summon_review_comment_id' => null, 'summon_quote' => "/yak How slow is this?\nIt already takes hours."]);

    runExternal($task, $sandbox, "## What changed in this run\n\nIt adds a few minutes.");

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/9/comments')
        && str_starts_with((string) $request['body'], "> /yak How slow is this?\n> It already takes hours.\n\nIt adds a few minutes."));
});

it('posts thread replies instead of a summary when the agent wrote them', function () {
    $sandbox = new FakeSandboxManager;
    $task = externalTask();
    $task->update(['description' => '- [c:4135] fix this please!']);

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.\n\n## Replies\n\n- [c:4135] Fixed in the latest commit.");

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4135/replies'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies'));
});

it('posts the summary reply when none of the agent replies match the description', function () {
    $sandbox = new FakeSandboxManager;
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.\n\n## Replies\n\n- [c:9999] Unrelated.");

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/comments/9999/replies'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies')
        && str_contains((string) $request['body'], 'Did it.'));
});

it('still succeeds without a second reply when the summary reply cannot be posted', function () {
    Http::fake(['api.github.com/*' => Http::response([], 500)]);
    $sandbox = new FakeSandboxManager;
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['body'], "couldn't finish"));
});
