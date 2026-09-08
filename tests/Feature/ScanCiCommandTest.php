<?php

use App\Channels\Contracts\CIBuildScanner;
use App\Channels\Drone\BuildScanner as DroneBuildScanner;
use App\Channels\GitHub\ActionsBuildScanner as GitHubActionsBuildScanner;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\CIBuildFailure;
use App\Enums\TaskStatus;
use App\Jobs\RunYakJob;
use App\Models\FlakyTestClaim;
use App\Models\Observation;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function fakeScannerWith(CIBuildFailure ...$failures): void
{
    $scanner = Mockery::mock(CIBuildScanner::class);
    $scanner->shouldReceive('getRecentFailures')
        ->andReturn(collect($failures));

    app()->instance(GitHubActionsBuildScanner::class, $scanner);
    app()->instance(DroneBuildScanner::class, $scanner);
}

function fakeEmptyScanner(): void
{
    fakeScannerWith();
}

test('scans all active repositories with ci_system configured', function () {
    Repository::factory()->create(['ci_system' => 'github_actions']);
    Repository::factory()->create(['ci_system' => 'drone']);
    Repository::factory()->create(['ci_system' => 'none']);
    Repository::factory()->inactive()->create(['ci_system' => 'github_actions']);

    fakeEmptyScanner();

    $this->artisan('yak:scan-ci')
        ->assertSuccessful()
        ->expectsOutputToContain('Created 0 task(s)');
});

test('scans only specified repository when --repo given', function () {
    $target = Repository::factory()->create(['slug' => 'target-repo', 'ci_system' => 'github_actions']);
    Repository::factory()->create(['slug' => 'other-repo', 'ci_system' => 'github_actions']);

    $scanner = Mockery::mock(CIBuildScanner::class);
    $scanner->shouldReceive('getRecentFailures')
        ->once()
        ->with(Mockery::on(fn (Repository $repo) => $repo->slug === 'target-repo'), 48)
        ->andReturn(collect());

    app()->instance(GitHubActionsBuildScanner::class, $scanner);

    $this->artisan('yak:scan-ci', ['--repo' => 'target-repo'])->assertSuccessful();
});

test('creates tasks for detected flaky tests with source=flaky-test', function () {
    Repository::factory()->create(['slug' => 'flaky-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'Expected status 200, got 500',
            buildUrl: 'https://github.com/org/repo/actions/runs/123',
            buildId: '123',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'flaky-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'flaky-repo')->first();
    expect($task)->not->toBeNull();
    expect($task->description)->toContain('flaky test');
    expect($task->source)->toBe('flaky-test');
    expect($task->external_id)->toBe(CIBuildFailure::commitExternalId('flaky-repo', 'sha-a'));
    expect($task->external_url)->toBe('https://github.com/org/repo/actions/runs/123');

    Queue::assertPushed(RunYakJob::class);
});

test('does not create a task while a live claim covers the test', function () {
    Repository::factory()->create(['slug' => 'dup-repo', 'ci_system' => 'github_actions']);

    $task = YakTask::factory()->pending()->create([
        'repo' => 'dup-repo',
        'source' => 'flaky-test',
    ]);

    FlakyTestClaim::create([
        'repo' => 'dup-repo',
        'test_class' => 'Tests\\Feature\\LoginTest',
        'yak_task_id' => $task->id,
        'created_at' => now(),
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'Error detail',
            buildUrl: 'https://github.com/org/repo/actions/runs/456',
            buildId: '456',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'dup-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'dup-repo')->count())->toBe(1);
    expect(Observation::where('kind', 'flaky_test.already_claimed')->count())->toBe(1);
    Queue::assertNotPushed(RunYakJob::class);
});

test('does nothing when no failures detected', function () {
    Repository::factory()->create(['ci_system' => 'github_actions']);

    fakeEmptyScanner();

    $this->artisan('yak:scan-ci')->assertSuccessful();

    Queue::assertNotPushed(RunYakJob::class);
});

test('skips repositories with unsupported ci_system', function () {
    Repository::factory()->create(['slug' => 'no-ci-repo', 'ci_system' => 'unsupported']);

    $this->artisan('yak:scan-ci', ['--repo' => 'no-ci-repo'])
        ->assertSuccessful()
        ->expectsOutputToContain('No CI scanner available');
});

test('handles scanner errors gracefully', function () {
    Repository::factory()->create(['slug' => 'error-repo', 'ci_system' => 'github_actions']);

    $scanner = Mockery::mock(CIBuildScanner::class);
    $scanner->shouldReceive('getRecentFailures')
        ->andThrow(new RuntimeException('API rate limit exceeded'));

    app()->instance(GitHubActionsBuildScanner::class, $scanner);

    $this->artisan('yak:scan-ci', ['--repo' => 'error-repo'])
        ->assertSuccessful()
        ->expectsOutputToContain('API rate limit exceeded');

    Queue::assertNotPushed(RunYakJob::class);
});

test('creates tasks with mode=fix', function () {
    Repository::factory()->create(['slug' => 'fix-repo', 'ci_system' => 'drone']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Unit\ParserTest > it parses CSV',
            output: 'Failed assertion',
            buildUrl: 'https://drone.example.com/org/repo/42',
            buildId: '42',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'fix-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'fix-repo')->first();
    expect($task->mode->value)->toBe('fix');
});

test('stores context as JSON with test metadata', function () {
    Repository::factory()->create(['slug' => 'ctx-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\ApiTest > it returns 200',
            output: 'Connection refused',
            buildUrl: 'https://github.com/org/repo/actions/runs/789',
            buildId: '789',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'ctx-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'ctx-repo')->first();

    /** @var array<string, string> $context */
    $context = json_decode($task->context, true);
    expect($context['tests'])->toHaveCount(1);
    expect($context['tests'][0])->toHaveKey('test_name', 'Tests\Feature\ApiTest > it returns 200');
    expect($context['tests'][0])->toHaveKey('test_class', 'Tests\Feature\ApiTest');
    expect($context['tests'][0])->toHaveKey('failure_output', 'Connection refused');
    expect($context)->toHaveKey('build_url');
    expect($context)->toHaveKey('build_id', '789');
    expect($context)->toHaveKey('commit_sha', 'sha-a');
});

test('uses max_failure_age_hours from config', function () {
    config(['yak.ci_scan.max_failure_age_hours' => 24]);
    Repository::factory()->create(['slug' => 'age-repo', 'ci_system' => 'github_actions']);

    $scanner = Mockery::mock(CIBuildScanner::class);
    $scanner->shouldReceive('getRecentFailures')
        ->once()
        ->with(Mockery::type(Repository::class), 24)
        ->andReturn(collect());

    app()->instance(GitHubActionsBuildScanner::class, $scanner);

    $this->artisan('yak:scan-ci', ['--repo' => 'age-repo'])->assertSuccessful();
});

test('dry-run reports detected failures without creating tasks or dispatching jobs', function () {
    Repository::factory()->create(['slug' => 'dry-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'Expected 200, got 500',
            buildUrl: 'https://example.com/runs/42',
            buildId: '42',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'dry-repo', '--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would create task for commit sha-a')
        ->expectsOutputToContain('Would have created 1 task');

    expect(YakTask::where('repo', 'dry-repo')->count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

test('flaky threshold: single failure on a feature branch does NOT create a task', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://example.com/runs/1',
            buildId: '1',
            branch: 'feature/foo',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])
        ->assertSuccessful()
        ->expectsOutputToContain('Below flaky threshold');

    expect(YakTask::where('repo', 'thresh-repo')->count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

test('flaky threshold: two failures from the SAME commit on a feature branch do NOT create a task', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://example.com/runs/1',
            buildId: '1',
            branch: 'feature/foo',
            commitSha: 'sha-a',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://example.com/runs/2',
            buildId: '2',
            branch: 'feature/foo',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'thresh-repo')->count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

test('task context lists every distinct build URL where the test failed', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://ci.example.com/runs/101',
            buildId: '101',
            branch: 'main',
            commitSha: 'sha-a',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://ci.example.com/runs/102',
            buildId: '102',
            branch: 'main',
            commitSha: 'sha-b',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://ci.example.com/runs/103',
            buildId: '103',
            branch: 'main',
            commitSha: 'sha-c',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'thresh-repo')->first();
    $context = json_decode($task->context, true);

    expect($context['tests'][0]['build_urls'])->toBe([
        'https://ci.example.com/runs/101',
        'https://ci.example.com/runs/102',
        'https://ci.example.com/runs/103',
    ]);
    expect($context['tests'][0]['failure_count'])->toBe(3);
});

test('flaky threshold: feature-branch failures across distinct commits do NOT create a task', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://example.com/runs/1',
            buildId: '1',
            branch: 'feature/foo',
            commitSha: 'sha-a',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky',
            buildUrl: 'https://example.com/runs/2',
            buildId: '2',
            branch: 'feature/bar',
            commitSha: 'sha-b',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])
        ->assertSuccessful()
        ->expectsOutputToContain('Below flaky threshold');

    expect(YakTask::where('repo', 'thresh-repo')->count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);
});

test('canonical failure is always picked from the default branch', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky on main',
            buildUrl: 'https://example.com/runs/10',
            buildId: '10',
            branch: 'main',
            commitSha: 'sha-main',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky on feature',
            buildUrl: 'https://example.com/runs/99',
            buildId: '99',
            branch: 'feature/foo',
            commitSha: 'sha-feature',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'thresh-repo')->first();
    expect($task->external_url)->toBe('https://example.com/runs/10');
});

test('flaky threshold: a single failure on the default branch DOES create a task', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'flaky on master',
            buildUrl: 'https://example.com/runs/42',
            buildId: '42',
            branch: 'main',
            commitSha: 'sha-a',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'thresh-repo')->first();
    expect($task)->not->toBeNull();
    Queue::assertPushed(RunYakJob::class);

    $context = json_decode($task->context, true);
    expect($context['tests'][0])->toHaveKey('failure_count', 1);
    expect($context['tests'][0]['distinct_commits'])->toBe(['sha-a']);
});

test('flaky threshold: truncated test names dedup via trailing-ellipsis stripping', function () {
    Repository::factory()->create([
        'slug' => 'thresh-repo',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);

    // Pest truncates long test names with a trailing `…`. Two builds that
    // produce the same truncated name on different commits should collapse
    // to one task. Our external_id is md5(normalizeTestName), and
    // normalizeTestName strips the trailing ellipsis so an inadvertent
    // extra trailing `…` (or whitespace around it) still hashes the same.
    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Browser\LoginTest > it can access change pa…',
            output: '',
            buildUrl: 'https://example.com/runs/1',
            buildId: '1',
            branch: 'main',
            commitSha: 'sha-a',
        ),
        new CIBuildFailure(
            testName: 'Tests\Browser\LoginTest > it can access change pa… ',
            output: '',
            buildUrl: 'https://example.com/runs/2',
            buildId: '2',
            branch: 'main',
            commitSha: 'sha-b',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'thresh-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'thresh-repo')->count())->toBe(1);
});

test('scan-ci command is scheduled every two hours', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        return str_contains($event->command ?? '', 'yak:scan-ci');
    });

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 */2 * * *');
});

test('groups tests failing at the same commit into one task', function () {
    Repository::factory()->create(['slug' => 'grouped-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Api\Integration\Common\AccuracyScoreAPITest > it scores',
            output: 'Call to undefined method',
            buildUrl: 'https://github.com/org/repo/actions/runs/900',
            buildId: '900',
            branch: 'main',
            commitSha: 'shared-sha',
        ),
        new CIBuildFailure(
            testName: 'Tests\Api\Integration\US\ParcelCentroidAPITest > it centroids',
            output: 'Call to undefined method',
            buildUrl: 'https://github.com/org/repo/actions/runs/901',
            buildId: '901',
            branch: 'main',
            commitSha: 'shared-sha',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'grouped-repo'])->assertSuccessful();

    $tasks = YakTask::where('repo', 'grouped-repo')->get();
    expect($tasks)->toHaveCount(1);

    $context = json_decode($tasks->first()->context, true);
    expect($context['tests'])->toHaveCount(2);
    expect($context['commit_sha'])->toBe('shared-sha');
    expect(collect($context['tests'])->pluck('test_class')->all())->toEqualCanonicalizing([
        'Tests\Api\Integration\Common\AccuracyScoreAPITest',
        'Tests\Api\Integration\US\ParcelCentroidAPITest',
    ]);

    expect(FlakyTestClaim::where('repo', 'grouped-repo')->count())->toBe(2);
    Queue::assertPushed(RunYakJob::class, 1);
});

test('separate commits still get separate tasks', function () {
    Repository::factory()->create(['slug' => 'split-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\AlphaTest > a',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/1',
            buildId: '1',
            branch: 'main',
            commitSha: 'sha-one',
        ),
        new CIBuildFailure(
            testName: 'Tests\Feature\BetaTest > b',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/2',
            buildId: '2',
            branch: 'main',
            commitSha: 'sha-two',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'split-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'split-repo')->count())->toBe(2);
});

test('a claim whose task failed is claimable again', function () {
    Repository::factory()->create(['slug' => 'retry-repo', 'ci_system' => 'github_actions']);

    $failed = YakTask::factory()->create([
        'repo' => 'retry-repo',
        'source' => 'flaky-test',
        'status' => TaskStatus::Failed,
    ]);

    FlakyTestClaim::create([
        'repo' => 'retry-repo',
        'test_class' => 'Tests\Feature\LoginTest',
        'yak_task_id' => $failed->id,
        'created_at' => now(),
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/77',
            buildId: '77',
            branch: 'main',
            commitSha: 'sha-new',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'retry-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'retry-repo')->count())->toBe(2);
});

test('a claim whose pull request closed unmerged is claimable again', function () {
    Repository::factory()->create(['slug' => 'closed-repo', 'ci_system' => 'github_actions']);

    $closed = YakTask::factory()->create([
        'repo' => 'closed-repo',
        'source' => 'flaky-test',
        'status' => TaskStatus::Success,
        'pr_url' => 'https://github.com/org/repo/pull/5',
        'pr_closed_at' => now()->subDay(),
    ]);

    FlakyTestClaim::create([
        'repo' => 'closed-repo',
        'test_class' => 'Tests\Feature\LoginTest',
        'yak_task_id' => $closed->id,
        'created_at' => now()->subDays(2),
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/78',
            buildId: '78',
            branch: 'main',
            commitSha: 'sha-new',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'closed-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'closed-repo')->count())->toBe(2);
});

test('a claim whose fix merged inside the scan window still suppresses', function () {
    Repository::factory()->create(['slug' => 'merged-repo', 'ci_system' => 'github_actions']);

    $merged = YakTask::factory()->create([
        'repo' => 'merged-repo',
        'source' => 'flaky-test',
        'status' => TaskStatus::Success,
        'pr_url' => 'https://github.com/org/repo/pull/6',
        'pr_merged_at' => now()->subHour(),
    ]);

    FlakyTestClaim::create([
        'repo' => 'merged-repo',
        'test_class' => 'Tests\Feature\LoginTest',
        'yak_task_id' => $merged->id,
        'created_at' => now()->subDay(),
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/79',
            buildId: '79',
            branch: 'main',
            commitSha: 'sha-new',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'merged-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'merged-repo')->count())->toBe(1);
});

test('a fix merged longer ago than the scan window no longer suppresses', function () {
    Repository::factory()->create(['slug' => 'stale-repo', 'ci_system' => 'github_actions']);

    $merged = YakTask::factory()->create([
        'repo' => 'stale-repo',
        'source' => 'flaky-test',
        'status' => TaskStatus::Success,
        'pr_url' => 'https://github.com/org/repo/pull/7',
        'pr_merged_at' => now()->subDays(10),
    ]);

    FlakyTestClaim::create([
        'repo' => 'stale-repo',
        'test_class' => 'Tests\Feature\LoginTest',
        'yak_task_id' => $merged->id,
        'created_at' => now()->subDays(10),
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/80',
            buildId: '80',
            branch: 'main',
            commitSha: 'sha-new',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'stale-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'stale-repo')->count())->toBe(2);
});

test('records a below-threshold observation for feature-branch-only failures', function () {
    Repository::factory()->create(['slug' => 'threshold-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/81',
            buildId: '81',
            branch: 'feature/thing',
            commitSha: 'sha-branch',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'threshold-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'threshold-repo')->count())->toBe(0);

    $observation = Observation::where('kind', 'flaky_test.below_threshold')->first();
    expect($observation)->not->toBeNull();
    expect($observation->outcome)->toBe('declined');
    expect($observation->subject)->toBe('Tests\Feature\LoginTest');
});

test('records an acted observation when a task is created', function () {
    Repository::factory()->create(['slug' => 'acted-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/82',
            buildId: '82',
            branch: 'main',
            commitSha: 'sha-acted',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'acted-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'acted-repo')->firstOrFail();
    $observation = Observation::where('kind', 'flaky_test.task_created')->first();

    expect($observation)->not->toBeNull();
    expect($observation->outcome)->toBe('acted');
    expect($observation->yak_task_id)->toBe($task->id);
});

test('truncated and full class names claim as one test', function () {
    Repository::factory()->create(['slug' => 'truncated-repo', 'ci_system' => 'github_actions']);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Feature\LoginTest > it logs in',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/83',
            buildId: '83',
            branch: 'main',
            commitSha: 'sha-trunc',
        ),
        new CIBuildFailure(
            testName: "Tests\Feature\LoginTest\u{2026}   RateLimitException",
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/84',
            buildId: '84',
            branch: 'main',
            commitSha: 'sha-trunc',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'truncated-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'truncated-repo')->count())->toBe(1);
    expect(FlakyTestClaim::where('repo', 'truncated-repo')->count())->toBe(1);
});

test('skips a test when an open pull request already changes its file', function () {
    config()->set('yak.channels.github.installation_id', 4242);

    Repository::factory()->create([
        'slug' => 'pr-repo',
        'ci_system' => 'github_actions',
        'github_full_name' => 'Geocodio/geocodio',
    ]);

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 2794, 'title' => 'Point the renamed helper at its call sites',
            'body' => '', 'html_url' => 'https://github.com/Geocodio/geocodio/pull/2794',
            'updated_at' => now()->toIso8601String(), 'merged_at' => null],
    ]);
    $github->shouldReceive('listRecentlyMergedPullRequests')->andReturn([]);
    $github->shouldReceive('listPullRequestFiles')->andReturn([
        ['filename' => 'tests/Api/Integration/Common/AccuracyScoreAPITest.php'],
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Api\Integration\Common\AccuracyScoreAPITest > it scores',
            output: 'Call to undefined method',
            buildUrl: 'https://github.com/org/repo/actions/runs/34152970174',
            buildId: '34152970174',
            branch: 'main',
            commitSha: 'sha-pr',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'pr-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'pr-repo')->count())->toBe(0);
    Queue::assertNotPushed(RunYakJob::class);

    $observation = Observation::where('kind', 'flaky_test.existing_pr')->first();
    expect($observation)->not->toBeNull();
    expect($observation->outcome)->toBe('declined');
    expect($observation->reference_url)->toBe('https://github.com/Geocodio/geocodio/pull/2794');
    expect($observation->metadata['match_reason'])
        ->toBe('changes tests/Api/Integration/Common/AccuracyScoreAPITest.php');

    expect(FlakyTestClaim::where('repo', 'pr-repo')->whereNotNull('skipped_pr_url')->count())->toBe(1);
});

test('a pull request touching an unrelated same-named test does not suppress', function () {
    config()->set('yak.channels.github.installation_id', 4242);

    Repository::factory()->create([
        'slug' => 'unrelated-repo',
        'ci_system' => 'github_actions',
        'github_full_name' => 'acme/widgets',
    ]);

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andReturn([
        ['number' => 12, 'title' => 'Tweak billing', 'body' => '',
            'html_url' => 'https://github.com/acme/widgets/pull/12',
            'updated_at' => now()->toIso8601String(), 'merged_at' => null],
    ]);
    $github->shouldReceive('listRecentlyMergedPullRequests')->andReturn([]);
    $github->shouldReceive('listPullRequestFiles')->andReturn([
        ['filename' => 'tests/Billing/UserTest.php'],
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Accounts\UserTest > it registers',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/55',
            buildId: '55',
            branch: 'main',
            commitSha: 'sha-unrelated',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'unrelated-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'unrelated-repo')->count())->toBe(1);
});

test('a pull request merged inside the scan window suppresses', function () {
    config()->set('yak.channels.github.installation_id', 4242);

    Repository::factory()->create([
        'slug' => 'merged-pr-repo',
        'ci_system' => 'github_actions',
        'github_full_name' => 'acme/widgets',
    ]);

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andReturn([]);
    $github->shouldReceive('listRecentlyMergedPullRequests')->andReturn([
        ['number' => 99, 'title' => 'Fix the helper', 'body' => '',
            'html_url' => 'https://github.com/acme/widgets/pull/99',
            'updated_at' => now()->subHour()->toIso8601String(),
            'merged_at' => now()->subHour()->toIso8601String()],
    ]);
    $github->shouldReceive('listPullRequestFiles')->andReturn([
        ['filename' => 'tests/Accounts/UserTest.php'],
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Accounts\UserTest > it registers',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/56',
            buildId: '56',
            branch: 'main',
            commitSha: 'sha-merged',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'merged-pr-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'merged-pr-repo')->count())->toBe(0);
    expect(Observation::where('kind', 'flaky_test.existing_pr')->count())->toBe(1);
});

test('a failed pull request lookup does not stop the scan', function () {
    config()->set('yak.channels.github.installation_id', 4242);

    Repository::factory()->create([
        'slug' => 'apifail-repo',
        'ci_system' => 'github_actions',
        'github_full_name' => 'acme/widgets',
    ]);

    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andThrow(new RuntimeException('rate limited'));

    fakeScannerWith(
        new CIBuildFailure(
            testName: 'Tests\Accounts\UserTest > it registers',
            output: 'boom',
            buildUrl: 'https://github.com/org/repo/actions/runs/57',
            buildId: '57',
            branch: 'main',
            commitSha: 'sha-apifail',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'apifail-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'apifail-repo')->count())->toBe(1);
});
