<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\AfterTurn\FinishResearchTurnJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\RiskProfile;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\RepositoryConfigParser;
use App\Services\RepositoryRiskProfiles;
use App\Services\YakConfigFiles;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

/**
 * A shell that answers `git rev-parse HEAD` with a fixed revision.
 */
function revisionShell(string $revision): FakeSandboxManager
{
    return new class($revision) extends FakeSandboxManager
    {
        public function __construct(private string $revision) {}

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null, array $env = []): ProcessResult
        {
            if (str_contains($command, 'git rev-parse HEAD')) {
                return Process::result($this->revision);
            }

            return parent::run($containerName, $command, $timeout, $asRoot, $input, $output);
        }
    };
}

/**
 * Writes a research turn for a task and finishes it with the given answer.
 *
 * @param  array<string, mixed>  $taskAttributes
 * @param  array<string, mixed>  $repositoryAttributes
 */
function researchTurn(string $summary, array $taskAttributes = [], array $repositoryAttributes = [], ?FakeSandboxManager $shell = null): YakTask
{
    $context = pendingRunYak(['mode' => TaskMode::Research, ...$taskAttributes], $shell);
    Repository::query()->where('slug', 'test-repo')->update($repositoryAttributes);
    $slug = (string) ($repositoryAttributes['slug'] ?? 'test-repo');
    $context['task']->update(['repo' => $slug]);

    (new ResearchYakJob($context['task']->refresh()))->handle();

    $run = TaskRun::query()->where('yak_task_id', $context['task']->id)->sole();
    $run->forceFill(['turn_state' => TurnState::Completed, 'last_result_event' => resultEvent($summary), 'assistant_turns' => 1])->save();
    runFinisher($run, FinishResearchTurnJob::class);

    return $context['task']->fresh();
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('artifacts');
    Queue::fake();
});

test('risk profile research saves a draft tied to the checked out revision without activating it', function () {
    $summary = json_encode([
        'areas' => [[
            'name' => 'Documentation', 'paths' => ['docs/*'], 'symbols' => [],
            'risk' => 'low', 'rationale' => 'Prose only.', 'evidence' => ['docs/readme.md:1'],
        ]],
        'unknowns' => [],
    ]);

    $task = researchTurn($summary, ['context' => json_encode(['risk_profile_draft' => true])], ['slug' => 'profile-repo'], revisionShell(str_repeat('a', 40)));

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(json_decode($task->context, true)['risk_profile_source_sha'])->toBe(str_repeat('a', 40))
        ->and(app(RepositoryRiskProfiles::class)->active('profile-repo'))->toBeNull();
    $rows = RiskProfile::where('repo', 'profile-repo')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->profile['source_sha'])->toBe(str_repeat('a', 40))
        ->and($rows[0]->profile)->not->toHaveKey('approved_by');
});

test('a rejected risk profile draft keeps the research output for a human to correct', function () {
    $task = researchTurn("Here is the profile:\n```json\n{\"areas\": []}\n```", ['context' => json_encode(['risk_profile_draft' => true])], ['slug' => 'profile-repo'], revisionShell(str_repeat('a', 40)));

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toContain('"areas": []')
        ->and($task->result_summary)->toContain('No draft risk profile was saved')
        ->and(RiskProfile::where('repo', 'profile-repo')->count())->toBe(0)
        ->and(app(RepositoryRiskProfiles::class)->active('profile-repo'))->toBeNull();
});

test('research completes with the answer, no branch and no push', function () {
    $shell = new FakeSandboxManager;

    $task = researchTurn('The middleware is idempotent.', ['source' => 'manual'], [], $shell);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('The middleware is idempotent.')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->branch_name)->toBeNull()
        ->and($shell->commandsMatching('git push'))->toBe([])
        ->and($shell->commandsMatching('git checkout -b'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

test('research fetches and resets the default branch before the turn', function () {
    $shell = new FakeSandboxManager;

    researchTurn('Done', ['source' => 'manual'], ['default_branch' => 'develop'], $shell);

    $commands = $shell->commands;
    $fetch = collect($commands)->search(fn ($command) => str_contains($command, 'git fetch origin develop'));
    $reset = collect($commands)->search(fn ($command) => str_contains($command, 'git reset --hard origin/develop'));
    expect($fetch)->not->toBeFalse()->and($reset)->toBeGreaterThan($fetch);
});

function reportShell(): FakeSandboxManager
{
    return new class extends FakeSandboxManager
    {
        public function fileExists(string $containerName, string $path): bool
        {
            return str_contains($path, 'research.html');
        }

        public function pullFile(string $containerName, string $remotePath, string $localPath): void
        {
            if (! is_dir(dirname($localPath))) {
                mkdir(dirname($localPath), 0755, true);
            }
            file_put_contents($localPath, '<html>research</html>');
        }
    };
}

test('research collects the HTML report from the sandbox when present', function () {
    $task = researchTurn('Research complete', ['source' => 'manual'], [], reportShell());

    $artifact = Artifact::where('yak_task_id', $task->id)->first();
    expect($artifact)->not->toBeNull()
        ->and($artifact->type)->toBe('research')
        ->and($artifact->filename)->toBe('research.html')
        ->and($artifact->disk_path)->toBe("{$task->id}/research.html");
});

test('research without a report completes with no artifact', function () {
    $task = researchTurn('Research complete', ['source' => 'manual']);

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(Artifact::where('yak_task_id', $task->id)->exists())->toBeFalse();
});

test('research posts the summary and the report link as a Slack thread reply', function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test-token', 'yak.channels.slack.signing_secret' => 'slack-secret']);

    $task = researchTurn('Analysis shows three bottlenecks', ['source' => 'slack', 'slack_channel' => 'C12345', 'slack_thread_ts' => '1234567890.123456'], [], reportShell());

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job) => str_contains($job->message, 'Analysis shows three bottlenecks')
        && str_contains($job->message, "/artifacts/{$task->id}/viewer/research.html"));
});

test('research that asks parks the task and collects no artifact', function () {
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";

    $task = researchTurn("One thing.\n\n{$block}", ['source' => 'manual'], [], reportShell());

    expect($task->status)->toBe(TaskStatus::AwaitingClarification)
        ->and(Artifact::where('yak_task_id', $task->id)->exists())->toBeFalse();
});

test('a research job runs on the yak-claude queue', function () {
    expect((new ResearchYakJob(YakTask::factory()->make()))->queue)->toBe('yak-claude');
});

function runRiskProfileResearch(string $areaName = 'billing'): YakTask
{
    $area = fn (string $name, string $risk): array => [
        'name' => $name, 'paths' => ['src/**'], 'symbols' => [],
        'risk' => $risk, 'rationale' => 'Because.', 'evidence' => ["{$name}/a.php:1"],
    ];
    $summary = json_encode(['areas' => [$area($areaName, 'high'), $area('docs', 'low')], 'unknowns' => ['Who owns cron?']]);

    return researchTurn(
        $summary,
        ['source' => 'cli', 'context' => json_encode(['risk_profile_draft' => true])],
        ['slug' => 'pr-profile', 'github_full_name' => 'acme/api', 'default_branch' => 'main'],
        revisionShell(str_repeat('b', 40)),
    );
}

test('risk profile research opens a PR listing new, changed and removed areas', function () {
    $current = app(YakConfigFiles::class)->riskProfile(['areas' => [
        ['name' => 'billing', 'paths' => ['billing/*'], 'symbols' => [], 'risk' => 'medium', 'rationale' => 'x', 'evidence' => ['a:1']],
        ['name' => 'legacy', 'paths' => ['legacy/*'], 'symbols' => [], 'risk' => 'low', 'rationale' => 'x', 'evidence' => ['a:1']],
    ], 'unknowns' => []]);
    fakeYakFiles(['risk-profile.yml' => $current]);
    fakeGithubConfigPullRequestApi();

    $task = runRiskProfileResearch();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(RiskProfile::where('repo', 'pr-profile')->count())->toBe(1);
    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/pulls')) {
            return false;
        }

        return $request['head'] === 'yak/risk-profile'
            && $request['title'] === 'Update Yak risk profile'
            && str_contains($request['body'], 'proposes these changes to `.yak/risk-profile.yml`. Merging approves them.')
            && str_contains($request['body'], '| billing | medium to **high** |')
            && str_contains($request['body'], '| docs | New, **low** |')
            && str_contains($request['body'], '| legacy | Removed |')
            && str_contains($request['body'], "1 open question remains. Open questions block Yak's auto-approval until they are answered in the file.")
            && str_contains($request['body'], str_repeat('b', 7));
    });
    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/git/blobs')) {
            return false;
        }
        $parsed = (new RepositoryConfigParser)->parse('risk-profile.yml', $request['content']);

        return $parsed['errors'] === [];
    });
});

test('risk profile research titles the PR as an addition when no file exists', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi();

    runRiskProfileResearch();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['title'] === 'Add Yak risk profile' && str_contains($request['body'], '| billing | New, **high** |'));
});

test('risk profile research keeps the draft and notes the failure when the PR cannot be opened', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi(500);

    $task = runRiskProfileResearch();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and(RiskProfile::where('repo', 'pr-profile')->count())->toBe(1)
        ->and($task->result_summary)->toContain('Could not open the risk profile PR');
});

test('risk profile research opens no PR when .yak/ could not be read', function () {
    fakeGithubConfigPullRequestApi();

    runRiskProfileResearch();

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls'));
});

test('an area name cannot inject markdown into the risk profile PR body', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi();

    runRiskProfileResearch("x\n## Approved <!-- y");

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/pulls')) {
            return false;
        }

        return str_contains($request['body'], '| x \#\# Approved \<\!\-\- y | New, **high** |')
            && ! str_contains($request['body'], "\n## Approved");
    });
});

test('regenerating with an open PR pushes a new commit and updates its body', function () {
    fakeYakFiles([]);
    fakeGithubConfigPullRequestApi(201, [
        'api.github.com/repos/acme/api/pulls?*' => Http::response([['number' => 9, 'title' => 'Existing', 'html_url' => 'https://github.com/acme/api/pull/9']]),
        'api.github.com/repos/acme/api/pulls/9' => Http::response(['number' => 9, 'html_url' => 'https://github.com/acme/api/pull/9']),
    ]);

    runRiskProfileResearch();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/git/commits'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/pulls/9')
        && str_contains($request['body'], '| billing | New, **high** |'));
});

test('risk profile research says so when .yak/ could not be read', function () {
    fakeGithubConfigPullRequestApi();

    $task = runRiskProfileResearch();

    expect($task->result_summary)->toContain('No risk profile PR was opened: Yak could not read `.yak/` from GitHub. Generate the profile again once it is reachable.');
});

test('an unchanged profile shows a single no-changes row', function () {
    $current = app(YakConfigFiles::class)->riskProfile(['areas' => [
        ['name' => 'billing', 'paths' => ['billing/*'], 'symbols' => [], 'risk' => 'high', 'rationale' => 'x', 'evidence' => ['a:1']],
        ['name' => 'docs', 'paths' => ['docs/*'], 'symbols' => [], 'risk' => 'low', 'rationale' => 'x', 'evidence' => ['a:1']],
    ], 'unknowns' => ['Who owns cron?']]);
    fakeYakFiles(['risk-profile.yml' => $current]);
    fakeGithubConfigPullRequestApi();

    runRiskProfileResearch();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && str_contains($request['body'], "| --- | --- |\n| No area changes | |"));
});
