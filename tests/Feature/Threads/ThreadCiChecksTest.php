<?php

use App\DataTransferObjects\TaskStop;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadCiChecks;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Cache::flush();
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'org/app', 'github_full_name' => 'org/app', 'ci_system' => 'github_actions', 'default_branch' => 'main', 'is_active' => true]);
    $this->user = User::factory()->create();
});

function ciThread(User $user, string $state = 'awaitingCi', array $attributes = []): Thread
{
    $stop = $state === 'failed' ? ['stop_summary' => TaskStop::ciStillFailing(2, ['Tests'], 'detail')->summary] : [];
    $task = YakTask::factory()->{$state}()->create(['repo' => 'org/app', 'branch_name' => 'yak/ci-1', 'pr_url' => 'https://github.com/org/app/pull/7', 'started_by_user_id' => $user->id, ...$stop, ...$attributes]);

    return Thread::query()->findOrFail($task->thread_id);
}

function checkRun(string $name, string $status = 'completed', ?string $conclusion = 'success', array $extra = []): array
{
    return ['name' => $name, 'status' => $status, 'conclusion' => $conclusion, 'html_url' => "https://github.com/org/app/runs/{$name}", 'started_at' => '2026-11-27T10:00:00Z', 'completed_at' => $status === 'completed' ? '2026-11-27T10:00:15Z' : null, ...$extra];
}

function fakeChecks(array $branch, array|int $default = []): void
{
    Http::fake([
        'api.github.com/repos/org/app/commits/yak*' => Http::response(['check_runs' => $branch]),
        'api.github.com/repos/org/app/commits/main/check-runs*' => is_int($default) ? Http::response([], $default) : Http::response(['check_runs' => $default]),
    ]);
}

it('shows running checks while CI runs', function () {
    $thread = ciThread($this->user);
    fakeChecks([checkRun('Tests', 'in_progress', null), checkRun('Lint'), checkRun('Docs', 'completed', 'skipped')]);

    $checks = ThreadCiChecks::for($thread, $this->user);

    expect($checks['state'])->toBe('running')
        ->and($checks['counts'])->toBe(['failing' => 0, 'passed' => 1, 'skipped' => 1, 'running' => 1])
        ->and(array_column($checks['checks'], 'name'))->toBe(['Tests', 'Lint', 'Docs'])
        ->and($checks['canRerun'])->toBeFalse();
});

it('marks a failure that also happens on the default branch as shared', function () {
    $thread = ciThread($this->user, 'failed', ['ci_diagnosis' => ['why' => 'Advisories.', 'next' => 'Upgrade.']]);
    fakeChecks(
        [checkRun('Audit', 'completed', 'failure', ['output' => ['title' => 'two advisories']]), checkRun('Tests')],
        [checkRun('Audit', 'completed', 'failure')],
    );

    $checks = ThreadCiChecks::for($thread, $this->user);

    expect($checks['state'])->toBe('failed')
        ->and($checks['sharedWithDefaultBranch'])->toBeTrue()
        ->and($checks['checks'][0])->toMatchArray(['name' => 'Audit', 'status' => 'failed', 'title' => 'two advisories', 'durationSeconds' => 15, 'alsoFailsOnDefaultBranch' => true])
        ->and($checks['diagnosis'])->toBe(['why' => 'Advisories.', 'next' => 'Upgrade.'])
        ->and($checks['canRerun'])->toBeTrue();
});

it('marks a failure the default branch passes as caused by the branch', function () {
    $thread = ciThread($this->user, 'failed');
    fakeChecks([checkRun('Tests', 'completed', 'failure')], [checkRun('Tests')]);

    $checks = ThreadCiChecks::for($thread, $this->user);

    expect($checks['sharedWithDefaultBranch'])->toBeFalse()
        ->and($checks['checks'][0]['alsoFailsOnDefaultBranch'])->toBeFalse();
});

it('leaves the default branch comparison open when it cannot be read', function () {
    $thread = ciThread($this->user, 'failed');
    fakeChecks([checkRun('Tests', 'completed', 'failure')], 500);

    $checks = ThreadCiChecks::for($thread, $this->user);

    expect($checks['checks'][0]['alsoFailsOnDefaultBranch'])->toBeNull()
        ->and($checks['sharedWithDefaultBranch'])->toBeFalse();
});

it('shows nothing when GitHub cannot be read, nothing ran, or everything passed', function () {
    $thread = ciThread($this->user, 'failed');

    fakeChecks([], 200);
    expect(ThreadCiChecks::for($thread, $this->user))->toBeNull();

    Cache::flush();
    Http::fake(['api.github.com/*' => Http::response([], 502)]);
    expect(ThreadCiChecks::for($thread, $this->user))->toBeNull();

    Cache::flush();
    fakeChecks([checkRun('Tests')]);
    expect(ThreadCiChecks::for($thread, $this->user))->toBeNull();
});

it('shows nothing for a repository that does not use GitHub Actions', function () {
    Repository::query()->where('slug', 'org/app')->update(['ci_system' => 'none']);
    fakeChecks([checkRun('Tests', 'completed', 'failure')]);

    expect(ThreadCiChecks::for(ciThread($this->user, 'failed'), $this->user))->toBeNull();
    Http::assertNothingSent();
});

it('keeps the stop card of a run that stopped for a reason other than CI', function () {
    $stop = TaskStop::yakSideProblem('Sandbox resume failed (exit 1): Error: The container is already running');
    $thread = ciThread($this->user, 'failed', ['stop_summary' => $stop->summary, 'error_log' => $stop->detail]);
    fakeChecks([checkRun('Tests', 'completed', 'failure')], [checkRun('Tests')]);

    expect(ThreadCiChecks::for($thread, $this->user))->toBeNull();
    Http::assertNothingSent();
});

it('shows the checks of a retry that changed nothing and could not re-run CI', function () {
    $thread = ciThread($this->user, 'failed', ['stop_summary' => TaskStop::yakSideProblem('x')->summary, 'error_log' => 'Retry made no changes to the branch, so CI could not run again on it.']);
    fakeChecks([checkRun('Audit', 'completed', 'failure')], [checkRun('Audit', 'completed', 'failure')]);

    expect(ThreadCiChecks::for($thread, $this->user)['sharedWithDefaultBranch'])->toBeTrue();
});

it('replaces the stop card on the thread page with the checks card', function () {
    $thread = ciThread($this->user, 'failed');
    fakeChecks([checkRun('Tests', 'completed', 'failure')], [checkRun('Tests')]);

    $this->actingAs($this->user)->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('card', 'ci_checks')
        ->where('ciChecks.state', 'failed')
        ->where('ciChecks.checks.0.name', 'Tests'));
});

it('re-runs the failed workflow runs and flashes the outcome', function () {
    $thread = ciThread($this->user, 'failed');
    Http::fake([
        'api.github.com/repos/org/app/actions/runs/7/rerun-failed-jobs' => Http::response([], 201),
        'api.github.com/repos/org/app/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/app/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 7, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'failure'],
            ['id' => 8, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'success'],
        ]]),
    ]);

    $this->actingAs($this->user)->post("/threads/{$thread->public_id}/ci/rerun")->assertSessionHas('success', 'Re-running the failed checks.');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/7/rerun-failed-jobs'));
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/8/rerun-failed-jobs'));
});

it('flashes GitHub\'s message when it refuses the re-run', function () {
    $thread = ciThread($this->user, 'failed');
    Http::fake([
        'api.github.com/repos/org/app/actions/runs/7/rerun-failed-jobs' => Http::response(['message' => 'Workflow is disabled'], 403),
        'api.github.com/repos/org/app/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/app/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 7, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'failure'],
        ]]),
    ]);

    $this->actingAs($this->user)->post("/threads/{$thread->public_id}/ci/rerun")->assertSessionHas('error', 'Workflow is disabled');
});
