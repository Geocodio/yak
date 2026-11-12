<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ConcernsDraft;
use App\Services\IncusSandboxManager;
use App\Services\RepositoryConfigParser;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

function runConcernsDraft(array $concern = []): YakTask
{
    $concern += ['name' => 'Customer charges', 'approval' => 'always_human', 'description' => 'What customers are charged.'];
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_concerns',
        resultSummary: "```json\n" . json_encode(['concerns' => [$concern]]) . "\n```",
        costUsd: 0.25, numTurns: 1, durationMs: 1000, isError: false, rawOutput: '{}',
    ));
    app()->instance(AgentRunner::class, $fake);
    app()->instance(IncusSandboxManager::class, new class extends FakeSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
        {
            if (str_contains($command, 'git rev-parse HEAD')) {
                return Process::result(str_repeat('b', 40));
            }

            return parent::run($containerName, $command, $timeout, $asRoot, $input, $output);
        }
    });
    Storage::fake('local');
    Process::fake(['*' => Process::result('')]);
    Repository::factory()->create(['slug' => 'pr-concerns', 'github_full_name' => 'acme/api', 'default_branch' => 'main']);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'pr-concerns', 'source' => 'cli', 'mode' => 'research',
        'context' => json_encode(['risk_profile_draft' => true]),
    ]);

    (new ResearchYakJob($task))->handle($fake);

    return $task->fresh();
}

function pullRequestWasOpened(): bool
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'))->isNotEmpty();
}

test('valid concerns open a PR whose file parses as version 2', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi();

    $task = runConcernsDraft();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toContain('Risk concerns PR: https://github.com/acme/api/pull/12');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['head'] === 'yak/risk-profile'
        && $request['title'] === 'Add Yak risk concerns'
        && str_contains($request['body'], 'Merging approves them.')
        && str_contains($request['body'], '| Customer charges | Added, **always_human** |'));
    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/git/blobs')) {
            return false;
        }
        $parsed = (new RepositoryConfigParser)->parse('risk-profile.yml', $request['content']);

        return $parsed['errors'] === []
            && $parsed['data']['version'] === 2
            && $parsed['data']['concerns'] === [['name' => 'Customer charges', 'approval' => 'always_human', 'description' => 'What customers are charged.']];
    });
});

test('output with an extra paths key is rejected and opens no PR', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi();

    $task = runConcernsDraft(['paths' => ['app/**']]);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toContain('No risk concerns PR was opened')
        ->and($task->result_summary)->toContain('"paths"')
        ->and(pullRequestWasOpened())->toBeFalse();
});

test('an existing version 2 file makes it an update listing changed and removed concerns', function () {
    fakeYakFiles(['risk-profile.yml' => concernsYaml()]);
    fakeGithubConfigPullRequestApi();

    runConcernsDraft(['name' => 'Customer charges and Stripe writes', 'approval' => 'when_tested']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['title'] === 'Update Yak risk concerns'
        && str_contains($request['body'], '| Customer charges and Stripe writes | Changed, always_human to **when_tested** |')
        && str_contains($request['body'], '| Usage metering and credit accounting | Removed |'));
});

test('no PR is opened when .yak/ could not be read', function () {
    fakeGithubConfigPullRequestApi();

    $task = runConcernsDraft();

    expect($task->result_summary)->toContain('No risk concerns PR was opened: Yak could not read `.yak/` from GitHub.')
        ->and(pullRequestWasOpened())->toBeFalse();
});

test('a failing PR call is noted in the summary', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi(500);

    expect(runConcernsDraft()->result_summary)->toContain('Could not open the risk concerns PR');
});

test('a concern name cannot inject markdown into the PR body', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi();

    runConcernsDraft(['name' => "x\n## Approved"]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && ! str_contains($request['body'], "\n## Approved"));
});

test('parse rejects invalid tiers and too many concerns', function () {
    expect(fn () => ConcernsDraft::parse('{"concerns":[{"name":"a","approval":"never","description":"d"}]}'))->toThrow(RuntimeException::class)
        ->and(fn () => ConcernsDraft::parse('not json'))->toThrow(RuntimeException::class)
        ->and(ConcernsDraft::parse('{"concerns":[{"name":"a","approval":"when_tested","description":"d"}]}'))->toHaveCount(1);
});
