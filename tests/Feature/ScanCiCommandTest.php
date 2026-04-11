<?php

use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Process::fake([
        'php artisan test *' => Process::result(output: "PASS  Tests\Feature\ExampleTest\n  ✓ it works\n\nTests: 1 passed"),
    ]);
});

test('scans all active repositories when no --repo given', function () {
    Repository::factory()->count(2)->create();
    Repository::factory()->inactive()->create();

    $this->artisan('yak:scan-ci')->assertSuccessful();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'php artisan test'), 2);
});

test('scans only specified repository when --repo given', function () {
    Repository::factory()->create(['slug' => 'target-repo']);
    Repository::factory()->create(['slug' => 'other-repo']);

    $this->artisan('yak:scan-ci', ['--repo' => 'target-repo'])->assertSuccessful();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'php artisan test'), 1);
});

test('creates tasks for detected flaky tests', function () {
    Repository::factory()->create(['slug' => 'flaky-repo']);

    Process::fake([
        'php artisan test *' => Process::result(output: "FAILED  Tests\Feature\LoginTest > it logs in\n  Expected status 200, got 500\n\nTests: 1 failed"),
    ]);

    $this->artisan('yak:scan-ci', ['--repo' => 'flaky-repo'])->assertSuccessful();

    $task = YakTask::where('repo', 'flaky-repo')->first();
    expect($task)->not->toBeNull();
    expect($task->description)->toContain('flaky test');
    expect($task->source)->toBe('ci-scan');

    Queue::assertPushed(RunYakJob::class);
});

test('skips creating duplicate tasks for same test', function () {
    Repository::factory()->create(['slug' => 'dup-repo']);

    YakTask::factory()->pending()->create([
        'repo' => 'dup-repo',
        'description' => 'Fix flaky test: Tests\Feature\LoginTest > it logs in',
    ]);

    Process::fake([
        'php artisan test *' => Process::result(output: "FAILED  Tests\Feature\LoginTest > it logs in\n  Error detail\n\nTests: 1 failed"),
    ]);

    $this->artisan('yak:scan-ci', ['--repo' => 'dup-repo'])->assertSuccessful();

    expect(YakTask::where('repo', 'dup-repo')->count())->toBe(1);
    Queue::assertNotPushed(RunYakJob::class);
});

test('does nothing when no failures detected', function () {
    Repository::factory()->create();

    $this->artisan('yak:scan-ci')->assertSuccessful();

    Queue::assertNotPushed(RunYakJob::class);
});

test('scan-ci command is scheduled every two hours', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        return str_contains($event->command ?? '', 'yak:scan-ci');
    });

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 */2 * * *');
});
