<?php

use App\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\DroneBuildScanner;
use App\Services\GitHubActionsBuildScanner;
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
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'flaky-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'flaky-repo')->first();
    expect($task)->not->toBeNull();
    expect($task->description)->toContain('flaky test');
    expect($task->source)->toBe('flaky-test');
    expect($task->external_id)->toBe('flaky-test:' . md5('Tests\Feature\LoginTest > it logs in'));
    expect($task->external_url)->toBe('https://github.com/org/repo/actions/runs/123');

    Queue::assertPushed(RunYakJob::class);
});

test('deduplicates using external_id and repo constraint', function () {
    Repository::factory()->create(['slug' => 'dup-repo', 'ci_system' => 'github_actions']);

    $testName = 'Tests\Feature\LoginTest > it logs in';
    $externalId = 'flaky-test:' . md5($testName);

    YakTask::factory()->pending()->create([
        'repo' => 'dup-repo',
        'external_id' => $externalId,
        'source' => 'flaky-test',
    ]);

    fakeScannerWith(
        new CIBuildFailure(
            testName: $testName,
            output: 'Error detail',
            buildUrl: 'https://github.com/org/repo/actions/runs/456',
            buildId: '456',
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'dup-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'dup-repo')->count())->toBe(1);
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
        ),
    );

    $this->artisan('yak:scan-ci', ['--repo' => 'ctx-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'ctx-repo')->first();

    /** @var array<string, string> $context */
    $context = json_decode($task->context, true);
    expect($context)->toHaveKey('test_name', 'Tests\Feature\ApiTest > it returns 200');
    expect($context)->toHaveKey('failure_output', 'Connection refused');
    expect($context)->toHaveKey('build_url');
    expect($context)->toHaveKey('build_id', '789');
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

test('scan-ci command is scheduled every two hours', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        return str_contains($event->command ?? '', 'yak:scan-ci');
    });

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 */2 * * *');
});
