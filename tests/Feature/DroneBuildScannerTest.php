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
