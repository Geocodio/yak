<?php

use App\Models\Repository;
use App\Services\DroneBuildScanner;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'yak.channels.drone.url' => 'https://drone.example.com',
        'yak.channels.drone.token' => 'test-token',
    ]);
});

test('scans all branches, only fetches logs for failed steps, and parses Pest FAILED lines', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'drone',
    ]);

    $recent = now()->subHour()->timestamp;

    Http::fake([
        // Builds list — scanner must not pass ?branch=...; PR-branch failure
        // should be picked up.
        'drone.example.com/api/repos/acme/app/builds' => Http::response([
            [
                'number' => 100,
                'status' => 'failure',
                'source' => 'feature/foo',
                'started' => $recent,
                'link' => 'https://drone.example.com/acme/app/100',
            ],
        ]),
        // Build detail — mixed step statuses.
        'drone.example.com/api/repos/acme/app/builds/100' => Http::response([
            'stages' => [
                [
                    'number' => 1,
                    'steps' => [
                        ['number' => 1, 'status' => 'success'],
                        ['number' => 19, 'status' => 'failure'],
                    ],
                ],
            ],
        ]),
        // Logs for the failing step — realistic Pest output.
        'drone.example.com/api/repos/acme/app/builds/100/logs/1/19' => Http::response([
            ['out' => '......s.......⨯'],
            ['out' => '   FAILED  Tests\\Dash\\Browser\\MultipleSessionsTest > it can access change password'],
            ['out' => '  Timeout 30000ms exceeded.'],
        ]),
    ]);

    $failures = app(DroneBuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1);
    expect($failures->first()->testName)->toContain('MultipleSessionsTest');
    expect($failures->first()->buildId)->toBe('100');

    Http::assertSent(fn ($req) => $req->url() === 'https://drone.example.com/api/repos/acme/app/builds');
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/logs/1/1?')
        || str_ends_with($req->url(), '/logs/1/1'));
});

test('pollBranchStatus returns null when no builds exist for the branch', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([]),
    ]);

    $result = app(DroneBuildScanner::class)->pollBranchStatus($repo, 'yak/abc', now()->subMinute());

    expect($result)->toBeNull();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'branch=yak%2Fabc'));
});

test('pollBranchStatus returns null while the build is still running', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([
            [
                'number' => 10,
                'status' => 'running',
                'started' => now()->timestamp,
                'link' => 'https://drone.example.com/acme/app/10',
            ],
        ]),
    ]);

    expect(app(DroneBuildScanner::class)->pollBranchStatus($repo, 'yak/abc', now()->subMinute()))
        ->toBeNull();
});

test('pollBranchStatus returns passed BuildResult on success', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([
            [
                'number' => 11,
                'status' => 'success',
                'started' => now()->timestamp,
                'link' => 'https://drone.example.com/acme/app/11',
                'after' => 'commit-sha-abc',
            ],
        ]),
    ]);

    $result = app(DroneBuildScanner::class)
        ->pollBranchStatus($repo, 'yak/abc', now()->subMinute());

    expect($result)->not->toBeNull();
    expect($result->passed)->toBeTrue();
    expect($result->externalId)->toBe('11');
    expect($result->output)->toBeNull();
    expect($result->commitSha)->toBe('commit-sha-abc');
});

test('pollBranchStatus returns failed BuildResult with logs on failure', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds?branch=*' => Http::response([
            [
                'number' => 12,
                'status' => 'failure',
                'started' => now()->timestamp,
                'link' => 'https://drone.example.com/acme/app/12',
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/12' => Http::response([
            'stages' => [
                ['number' => 1, 'steps' => [
                    ['number' => 5, 'status' => 'success'],
                    ['number' => 19, 'status' => 'failure'],
                ]],
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/12/logs/1/19' => Http::response([
            ['out' => '   FAILED  Tests\\LoginTest > it logs in'],
        ]),
    ]);

    $result = app(DroneBuildScanner::class)
        ->pollBranchStatus($repo, 'yak/abc', now()->subMinute());

    expect($result->passed)->toBeFalse();
    expect($result->output)->toContain('FAILED');
});

test('pollBranchStatus ignores builds started before the cutoff (retry race)', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    $taskResetAt = now();
    // Old failed build from previous attempt, started 10 minutes before retry.
    $staleBuildStartedAt = $taskResetAt->copy()->subMinutes(10)->timestamp;

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds*' => Http::response([
            [
                'number' => 9,
                'status' => 'failure',
                'started' => $staleBuildStartedAt,
                'link' => 'https://drone.example.com/acme/app/9',
            ],
        ]),
    ]);

    // Should return null — the only build is older than the cutoff.
    expect(app(DroneBuildScanner::class)->pollBranchStatus($repo, 'yak/abc', $taskResetAt))
        ->toBeNull();
});

test('skips builds older than the cutoff', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'ci_system' => 'drone',
    ]);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds' => Http::response([
            [
                'number' => 99,
                'status' => 'failure',
                'source' => 'master',
                'started' => now()->subDays(7)->timestamp,
                'link' => 'https://drone.example.com/acme/app/99',
            ],
        ]),
    ]);

    expect(app(DroneBuildScanner::class)->getRecentFailures($repo, 48))->toHaveCount(0);

    // Should not have fetched build detail for an out-of-window build.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/builds/99'));
});
