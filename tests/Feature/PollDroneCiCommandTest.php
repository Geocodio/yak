<?php

use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config([
        'yak.channels.drone.url' => 'https://drone.example.com',
        'yak.channels.drone.token' => 'test-token',
    ]);
});

function fakeDroneSuccessFor(string $repoSlug, string $branch, int $startedAt): void
{
    Http::fake([
        "drone.example.com/api/repos/{$repoSlug}/builds*" => Http::response([
            [
                'number' => 100,
                'status' => 'success',
                'started' => $startedAt,
                'link' => "https://drone.example.com/{$repoSlug}/100",
            ],
        ]),
    ]);
}

test('dispatches ProcessCIResultJob when build succeeds', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
    ]);

    fakeDroneSuccessFor($repo->slug, 'yak/feature', now()->timestamp);

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Queue::assertPushed(
        ProcessCIResultJob::class,
        fn (ProcessCIResultJob $job) => $job->task->id === $task->id
            && $job->passed === true
            && $job->output === null,
    );
});

test('dispatches ProcessCIResultJob with output when build fails', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
    ]);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds?branch=*' => Http::response([
            [
                'number' => 101,
                'status' => 'failure',
                'started' => now()->timestamp,
                'link' => 'https://drone.example.com/acme/app/101',
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/101' => Http::response([
            'stages' => [
                ['number' => 1, 'steps' => [
                    ['number' => 19, 'status' => 'failure'],
                ]],
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/101/logs/1/19' => Http::response([
            ['out' => '   FAILED  Tests\\LoginTest > it logs in'],
        ]),
    ]);

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Queue::assertPushed(
        ProcessCIResultJob::class,
        fn (ProcessCIResultJob $job) => $job->task->id === $task->id
            && $job->passed === false
            && str_contains((string) $job->output, 'FAILED'),
    );
});

test('does not dispatch when build is still running', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
    ]);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([
            [
                'number' => 50,
                'status' => 'running',
                'started' => now()->timestamp,
                'link' => 'https://drone.example.com/acme/app/50',
            ],
        ]),
    ]);

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Queue::assertNotPushed(ProcessCIResultJob::class);
});

test('skips tasks on non-drone repos', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/gh', 'ci_system' => 'github_actions']);
    YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
    ]);

    Http::fake(); // any outbound call would trip the test

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Http::assertNothingSent();
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

test('skips tasks not in awaiting_ci', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    YakTask::factory()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
        'status' => TaskStatus::Success,
    ]);

    Http::fake();

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Http::assertNothingSent();
});

test('skips tasks with a null branch_name', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => null,
    ]);

    Http::fake();

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Http::assertNothingSent();
});

test('logs a warning and keeps going when Drone API errors', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);
    YakTask::factory()->awaitingCi()->create([
        'repo' => $repo->slug,
        'branch_name' => 'yak/feature',
    ]);

    Http::fake(fn () => throw new RuntimeException('Drone down'));

    Log::spy();

    $this->artisan('yak:poll-drone-ci')->assertSuccessful();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Drone poll failed'
            && ($context['error'] ?? null) === 'Drone down',
    );
});

test('scheduled every minute with overlap prevention', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'yak:poll-drone-ci'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('* * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});
