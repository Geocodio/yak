<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RunFollowUpJob;
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
        isError: false, clarificationNeeded: false, clarificationOptions: [], rawOutput: '{}',
    ));
    app()->instance(AgentRunner::class, $agent);
    app()->instance(IncusSandboxManager::class, $sandbox);

    (new RunFollowUpJob($task))->handle($agent);
}

it('rebases, pushes without force, replies, and finishes without CI', function () {
    $sandbox = new FakeSandboxManager;
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nScoped `_warnings` to the status schema.\n\n## PR description\n\nRewritten description.");

    expect($sandbox->commandsMatching('git pull --rebase origin feature/warnings'))->toHaveCount(1)
        ->and($sandbox->commandsMatching('git push origin HEAD:feature/warnings'))->toHaveCount(1)
        ->and($sandbox->commandsMatching('--force'))->toBe([]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->pr_body_update)->toBeNull();

    Queue::assertNotPushed(ProcessCIResultJob::class);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies')
        && str_contains($request['body'], 'Scoped `_warnings`'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

it('aborts the rebase, replies, and fails when the branch moved', function () {
    $sandbox = (new FakeSandboxManager)->failCommand('git pull --rebase', 'CONFLICT');
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.");

    expect($sandbox->commandsMatching('git rebase --abort'))->toHaveCount(1)
        ->and($sandbox->commandsMatching('git push'))->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['body'], "couldn't finish"));
});

it('replies with the answer when the agent made no commits', function () {
    $sandbox = (new FakeSandboxManager)->setCommitCount(0);
    $task = externalTask();

    runExternal($task, $sandbox, "## What changed in this run\n\nNo change needed: the schema is already split.");

    expect($sandbox->commandsMatching('git push'))->toBe([])
        ->and($task->fresh()->status)->toBe(TaskStatus::Success);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['body'], 'No change needed'));
});

it('posts thread replies instead of a summary when the agent wrote them', function () {
    $sandbox = new FakeSandboxManager;
    $task = externalTask();
    $task->update(['description' => '- [c:4135] fix this please!']);

    runExternal($task, $sandbox, "## What changed in this run\n\nDid it.\n\n## Replies\n\n- [c:4135] Fixed in the latest commit.");

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4135/replies'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies'));
});
