<?php

use App\Channels\GitHub\AppService;
use App\Enums\TaskStatus;
use App\Jobs\HandleCiJobFailureJob;
use App\Jobs\RunYakJob;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\Observation;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    config()->set('yak.channels.github.installation_id', 99);

    $this->repository = Repository::factory()->create([
        'slug' => 'acme/widgets',
        'github_full_name' => 'acme/widgets',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{run: array<string, mixed>, excerpt: ?string, ancestor: bool, pullRequest: ?array<string, mixed>}
 */
function fakeFailureGitHub(array $overrides = []): AppService
{
    $options = array_merge([
        'run' => ['path' => '.github/workflows/ci.yml', 'name' => 'CI', 'head_repository_full_name' => 'acme/widgets', 'head_sha' => 'x', 'head_branch' => 'main'],
        'excerpt' => 'boom',
        'ancestor' => true,
        'pullRequest' => null,
        'runException' => null,
    ], $overrides);

    $mock = Mockery::mock(AppService::class);

    if ($options['runException'] !== null) {
        $mock->shouldReceive('getWorkflowRun')->andThrow($options['runException']);
    } else {
        $mock->shouldReceive('getWorkflowRun')->andReturn($options['run']);
    }

    // Each call tries the repo lock: failing to get it means a GitHub call ran while the lock was held.
    $guarded = function (mixed $value) {
        return function () use ($value) {
            $lock = Cache::lock('ci-failure:acme/widgets', 30);

            if ($lock->get()) {
                $lock->release();
            } else {
                $GLOBALS['ciFailureGitHubCallsUnderLock'][] = true;
            }

            return $value;
        };
    };

    $GLOBALS['ciFailureGitHubCallsUnderLock'] = [];
    $mock->shouldReceive('getJobLogExcerpt')->andReturnUsing($guarded($options['excerpt']));
    $mock->shouldReceive('getBranchHeadSha')->andReturnUsing($guarded('default-head'));
    $mock->shouldReceive('compareCommits')->andReturnUsing($guarded(['status' => 'diverged', 'merge_base_sha' => 'merge-base']));
    $mock->shouldReceive('isAncestor')->andReturnUsing($guarded($options['ancestor']));
    $mock->shouldReceive('findPullRequestForCommit')->andReturnUsing($guarded($options['pullRequest']));

    app()->instance(AppService::class, $mock);

    return $mock;
}

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function failurePayload(array $job = []): array
{
    static $jobId = 5000;
    $jobId++;

    return [
        'action' => 'completed',
        'workflow_job' => array_merge([
            'id' => $jobId,
            'run_id' => 77,
            'name' => 'Static Analysis',
            'workflow_name' => 'CI',
            'conclusion' => 'failure',
            'head_branch' => 'main',
            'head_sha' => 'sha-' . $jobId,
            'html_url' => "https://github.com/acme/widgets/actions/runs/77/job/{$jobId}",
            'completed_at' => now()->toIso8601String(),
            'steps' => [
                ['name' => 'Checkout', 'conclusion' => 'success'],
                ['name' => 'Run phpstan', 'conclusion' => 'failure'],
            ],
        ], $job),
        'repository' => ['id' => 1, 'full_name' => 'acme/widgets'],
        'sender' => ['login' => 'octocat', 'type' => 'User'],
    ];
}

/**
 * @param  array<string, mixed>  $job
 */
function runFailureJob(Repository $repository, array $job = []): void
{
    (new HandleCiJobFailureJob($repository->id, failurePayload($job)))->handle(app(AppService::class));
}

function ciFailureTasks()
{
    return YakTask::query()->where('source', 'ci-failure');
}

it('ignores fork runs with a fork_run observation', function () {
    fakeFailureGitHub(['run' => ['path' => '.github/workflows/ci.yml', 'name' => 'CI', 'head_repository_full_name' => 'someone/widgets', 'head_sha' => 'x', 'head_branch' => 'main']]);

    runFailureJob($this->repository);

    expect(CiJobFailure::count())->toBe(0)
        ->and(ciFailureTasks()->count())->toBe(0)
        ->and(Observation::where('kind', 'ci_failure.fork_run')->count())->toBe(1);
});

it('creates a ci-failure Fix task with context and claims when qualified', function () {
    fakeFailureGitHub();

    runFailureJob($this->repository);

    $task = ciFailureTasks()->sole();
    $context = json_decode($task->context, true);

    expect($task->description)->toBe('Fix CI: Static Analysis failing on main')
        ->and($task->mode->value)->toBe('fix')
        ->and($context['job_name'])->toBe('Static Analysis')
        ->and($context['workflow_path'])->toBe('.github/workflows/ci.yml')
        ->and($context['failures'][0]['failed_step'])->toBe('Run phpstan')
        ->and($context['failures'][0]['log_excerpt'])->toBe('boom')
        ->and(CiFailureClaim::where('yak_task_id', $task->id)->count())->toBe(1)
        ->and(Observation::where('kind', 'ci_failure.task_created')->count())->toBe(1);

    Queue::assertPushed(RunYakJob::class);
});

it('describes a cross-branch failure by its branch count', function () {
    fakeFailureGitHub();
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_name' => 'Static Analysis', 'failed_step' => 'Run phpstan']);

    runFailureJob($this->repository, ['head_branch' => 'feature/b']);

    expect(ciFailureTasks()->sole()->description)->toBe('Fix CI: Static Analysis failing on 2 branches');
});

it('extracts advisory ids into context', function () {
    fakeFailureGitHub(['excerpt' => 'Found GHSA-cfgh-2345-jmpq and CVE-2026-12345 and GHSA-cfgh-2345-jmpq']);

    runFailureJob($this->repository);

    $task = ciFailureTasks()->sole();

    expect(json_decode($task->context, true)['advisory_ids'])->toBe(['GHSA-cfgh-2345-jmpq', 'CVE-2026-12345'])
        ->and($task->description)->toBe('Fix CI: Static Analysis (GHSA-cfgh-2345-jmpq, CVE-2026-12345)');
});

it('stays silent for a single red branch', function () {
    fakeFailureGitHub();

    runFailureJob($this->repository, ['head_branch' => 'feature/a']);

    expect(CiJobFailure::count())->toBe(1)
        ->and(ciFailureTasks()->count())->toBe(0)
        ->and(Observation::count())->toBe(0);
});

it('skips a job that failed only because a needed job failed', function () {
    fakeFailureGitHub();

    runFailureJob($this->repository, ['needs' => ['build'], 'steps' => []]);

    expect(CiJobFailure::count())->toBe(0)
        ->and(ciFailureTasks()->count())->toBe(0);
});

it('declines with task_in_flight while a ci-failure task is working and claims the job on that task', function () {
    fakeFailureGitHub();
    $working = YakTask::factory()->create(['repo' => 'acme/widgets', 'source' => 'ci-failure', 'status' => TaskStatus::Running, 'pr_url' => null]);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(1)
        ->and(Observation::where('kind', 'ci_failure.task_in_flight')->count())->toBe(1)
        ->and(CiFailureClaim::where('yak_task_id', $working->id)->where('job_name', 'Static Analysis')->count())->toBe(1);
});

it('declines an already claimed job while the fix PR is open', function () {
    fakeFailureGitHub();
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets', 'source' => 'ci-failure', 'status' => TaskStatus::Success,
        'pr_url' => 'https://github.com/acme/widgets/pull/5', 'pr_merged_at' => null, 'pr_closed_at' => null,
    ]);
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'yak_task_id' => $task->id]);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(1)
        ->and(Observation::where('kind', 'ci_failure.already_claimed')->count())->toBe(1);
});

it('does not suppress a branch that already contains the fix merge sha', function () {
    fakeFailureGitHub(['ancestor' => true]);
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'fix_merge_sha' => 'fixsha']);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(1);
});

it('suppresses a stale branch missing the fix merge sha within the claim window', function () {
    fakeFailureGitHub(['ancestor' => false]);
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'fix_merge_sha' => 'fixsha']);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(0)
        ->and(Observation::where('kind', 'ci_failure.already_claimed')->count())->toBe(1);
});

it('respects blocked_until', function () {
    fakeFailureGitHub();
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'blocked_until' => now()->addDay()]);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(0)
        ->and(Observation::where('kind', 'ci_failure.already_claimed')->count())->toBe(1);
});

it('throttles repeated declined observations to one per hour', function () {
    fakeFailureGitHub();
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'blocked_until' => now()->addDay()]);

    runFailureJob($this->repository);
    runFailureJob($this->repository);
    runFailureJob($this->repository);

    expect(Observation::where('kind', 'ci_failure.already_claimed')->count())->toBe(1);
});

it('sets the merged PR author as responsible for a default-branch failure', function () {
    fakeFailureGitHub(['pullRequest' => ['number' => 3, 'title' => 'Break it', 'url' => 'u', 'author' => 'Jane Dev']]);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->sole()->responsible_name)->toBe('Jane Dev');
});

it('creates one task when ten branches fail concurrently', function () {
    fakeFailureGitHub();

    foreach (range(1, 10) as $number) {
        runFailureJob($this->repository, ['head_branch' => "feature/{$number}"]);
    }

    expect(ciFailureTasks()->count())->toBe(1)
        ->and(CiJobFailure::count())->toBe(10);
});

it('rethrows for retry when the run lookup fails and creates no task', function () {
    fakeFailureGitHub(['runException' => new RuntimeException('GitHub down')]);

    expect(fn () => runFailureJob($this->repository))->toThrow(RuntimeException::class);

    expect(ciFailureTasks()->count())->toBe(0);
});

it('keeps suppressing when a claim is blocked even though the branch contains the fix sha', function () {
    fakeFailureGitHub(['ancestor' => true]);
    CiFailureClaim::factory()->create(['job_name' => 'Static Analysis', 'fix_merge_sha' => 'fixsha', 'blocked_until' => now()->addDay()]);

    runFailureJob($this->repository);

    expect(ciFailureTasks()->count())->toBe(0)
        ->and(Observation::where('kind', 'ci_failure.already_claimed')->count())->toBe(1);
});

it('does not call GitHub while holding the repo lock', function () {
    fakeFailureGitHub(['pullRequest' => ['number' => 3, 'title' => 't', 'url' => 'u', 'author' => 'Jane Dev']]);
    CiFailureClaim::factory()->create(['job_name' => 'Other Job', 'fix_merge_sha' => 'fixsha']);
    CiJobFailure::factory()->create(['branch' => 'feature/a', 'job_name' => 'Static Analysis', 'failed_step' => 'Run phpstan']);

    runFailureJob($this->repository, ['head_branch' => 'feature/b']);

    expect(ciFailureTasks()->count())->toBe(1)
        ->and($GLOBALS['ciFailureGitHubCallsUnderLock'])->toBe([]);
});
