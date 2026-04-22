<?php

use App\Channels\Drone\BuildScanner;
use App\Models\Repository;
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

    $failures = app(BuildScanner::class)->getRecentFailures($repo, 48);

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

    $result = app(BuildScanner::class)->pollBranchStatus($repo, 'yak/abc', now()->subMinute());

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

    expect(app(BuildScanner::class)->pollBranchStatus($repo, 'yak/abc', now()->subMinute()))
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

    $result = app(BuildScanner::class)
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

    $result = app(BuildScanner::class)
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
    expect(app(BuildScanner::class)->pollBranchStatus($repo, 'yak/abc', $taskResetAt))
        ->toBeNull();
});

test('parser rejects FAILED lines without a Class > description shape', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds' => Http::response([
            [
                'number' => 7,
                'status' => 'failure',
                'source' => 'master',
                'started' => now()->subMinute()->timestamp,
                'link' => 'https://drone.example.com/acme/app/7',
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/7' => Http::response([
            'stages' => [['number' => 1, 'steps' => [['number' => 1, 'status' => 'failure']]]],
        ]),
        // Realistic log: one genuine FAILED plus some noise.
        'drone.example.com/api/repos/acme/app/builds/7/logs/1/1' => Http::response([
            ['out' => 'Build step "deploy" FAILED'],
            ['out' => '   FAILED  Tests\\LoginTest > it logs in'],
            ['out' => '  AssertionError: expected true'],
            ['out' => 'Tests:    1 failed, 8 skipped, 64 passed'],
            ['out' => 'FAILED pipeline'],
        ]),
    ]);

    $failures = app(BuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1);
    expect($failures->first()->testName)->toBe('Tests\\LoginTest > it logs in');
    expect($failures->first()->output)->toContain('AssertionError');
    expect($failures->first()->output)->not->toContain('Tests:'); // summary line stops capture
});

test('parser strips ANSI colour codes before matching', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds' => Http::response([
            [
                'number' => 8,
                'status' => 'failure',
                'source' => 'master',
                'started' => now()->subMinute()->timestamp,
                'link' => 'https://drone.example.com/acme/app/8',
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/8' => Http::response([
            'stages' => [['number' => 1, 'steps' => [['number' => 1, 'status' => 'failure']]]],
        ]),
        'drone.example.com/api/repos/acme/app/builds/8/logs/1/1' => Http::response([
            // Pest with colour on: FAILED header is wrapped in escape sequences.
            ['out' => "   \e[41;1m FAILED \e[49;22m \e[1mTests\\LoginTest\e[22m \e[90m>\e[39m it logs in"],
        ]),
    ]);

    $failures = app(BuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1);
    expect($failures->first()->testName)->toBe('Tests\\LoginTest > it logs in');
});

test('scanner populates branch and commit sha from the Drone build payload', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/app', 'ci_system' => 'drone']);

    Http::fake([
        'drone.example.com/api/repos/acme/app/builds' => Http::response([
            [
                'number' => 9,
                'status' => 'failure',
                'source' => 'feature/xyz',
                'after' => 'commit-sha-9',
                'started' => now()->subMinute()->timestamp,
                'link' => 'https://drone.example.com/acme/app/9',
            ],
        ]),
        'drone.example.com/api/repos/acme/app/builds/9' => Http::response([
            'stages' => [['number' => 1, 'steps' => [['number' => 1, 'status' => 'failure']]]],
        ]),
        'drone.example.com/api/repos/acme/app/builds/9/logs/1/1' => Http::response([
            ['out' => '   FAILED  Tests\\LoginTest > it logs in'],
        ]),
    ]);

    $failures = app(BuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures->first()->branch)->toBe('feature/xyz');
    expect($failures->first()->commitSha)->toBe('commit-sha-9');
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

    expect(app(BuildScanner::class)->getRecentFailures($repo, 48))->toHaveCount(0);

    // Should not have fetched build detail for an out-of-window build.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/builds/99'));
});
