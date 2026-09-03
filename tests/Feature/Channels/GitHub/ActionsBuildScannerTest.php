<?php

use App\Channels\GitHub\ActionsBuildScanner;
use App\Channels\GitHub\AppService;
use App\Models\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['yak.channels.github.installation_id' => 4242]);

    $github = $this->mock(AppService::class);
    // Built lazily: the client must be created after each test installs its
    // Http::fake(), otherwise the request escapes to the real API.
    $github->shouldReceive('installationClient')
        ->with(4242)
        ->andReturnUsing(fn () => Http::withToken('installation-token'));
});

function failedRunResponse(int $runId = 900): array
{
    return [
        'workflow_runs' => [
            [
                'id' => $runId,
                'html_url' => "https://github.com/acme/app/actions/runs/{$runId}",
                'conclusion' => 'failure',
                'created_at' => now()->subHour()->toIso8601String(),
                'head_branch' => 'master',
                'head_sha' => 'abc123',
            ],
        ],
    ];
}

/** A realistic GitHub Actions job log: timestamp-prefixed, truncated name. */
function pestJobLog(string $testName = 'Tests\Dash\Feature\OneOffInvoiceIssuanceTest…'): string
{
    return implode("\n", [
        '2026-08-27T07:24:30.0000000Z   ────────────────────────────────────',
        "2026-08-27T07:24:34.4244357Z    FAILED  {$testName}   RateLimitException",
        '2026-08-27T07:24:34.4244400Z   at vendor/stripe/stripe-php/lib/Exception/ApiErrorException.php:38',
        '2026-08-27T07:24:35.0000000Z   Tests:    1 failed, 7227 passed (16779 assertions)',
    ]);
}

test('reads logs from failed test jobs and parses the flaky test out of them', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 11, 'name' => 'Test (Main)', 'conclusion' => 'failure'],
            ],
        ]),
        'api.github.com/repos/acme/app/actions/jobs/11/logs' => Http::response(pestJobLog()),
    ]);

    $failures = app(ActionsBuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1);

    $failure = $failures->first();

    expect($failure->testName)->toBe('Tests\Dash\Feature\OneOffInvoiceIssuanceTest…')
        ->and($failure->output)->toContain('RateLimitException')
        ->and($failure->buildId)->toBe('900')
        ->and($failure->buildUrl)->toBe('https://github.com/acme/app/actions/runs/900')
        ->and($failure->branch)->toBe('master')
        ->and($failure->commitSha)->toBe('abc123');
});

// "Notify Slack (tests)" is a real job name in Geocodio/geocodio. It reports
// on a test run without running one, and the word "tests" in its name is
// enough to match the include list — so the exclude list has to win.
test('skips non-test jobs so build, lint, deploy and notify failures are never read', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 21, 'name' => 'Build App Images', 'conclusion' => 'failure'],
                ['id' => 22, 'name' => 'Security Audit', 'conclusion' => 'failure'],
                ['id' => 23, 'name' => 'Lint', 'conclusion' => 'failure'],
                ['id' => 24, 'name' => 'Deploy staging', 'conclusion' => 'failure'],
                ['id' => 25, 'name' => 'Notify Slack (tests)', 'conclusion' => 'failure'],
                ['id' => 26, 'name' => 'Move the branch tag', 'conclusion' => 'failure'],
            ],
        ]),
    ]);

    expect(app(ActionsBuildScanner::class)->getRecentFailures($repo, 48))->toBeEmpty();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/logs'));
});

test('skips jobs that passed, even when their name looks like a test job', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 31, 'name' => 'Test (Browser)', 'conclusion' => 'success'],
                ['id' => 32, 'name' => 'Test (Skipped)', 'conclusion' => 'skipped'],
            ],
        ]),
    ]);

    expect(app(ActionsBuildScanner::class)->getRecentFailures($repo, 48))->toBeEmpty();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/logs'));
});

test('scopes the run query to the default branch, failures, and the age cutoff', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(['workflow_runs' => []]),
    ]);

    app(ActionsBuildScanner::class)->getRecentFailures($repo, 48);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'branch=master')
            && str_contains($request->url(), 'status=failure')
            && str_contains($request->url(), 'created=');
    });
});

test('honours a custom test job pattern list', function () {
    config(['yak.ci_scan.test_job_patterns' => ['pytest']]);

    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 41, 'name' => 'Test (Main)', 'conclusion' => 'failure'],
                ['id' => 42, 'name' => 'pytest suite', 'conclusion' => 'failure'],
            ],
        ]),
        'api.github.com/repos/acme/app/actions/jobs/42/logs' => Http::response(pestJobLog('Tests\Feature\PyTest > it runs')),
    ]);

    $failures = app(ActionsBuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/actions/jobs/42/logs'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/actions/jobs/41/logs'));
});

test('raises instead of reporting zero failures when GitHub rejects a request', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    // A silently-swallowed 404 is what hid this scanner's breakage for
    // months; an unreachable endpoint must surface as a failed scan.
    expect(fn () => app(ActionsBuildScanner::class)->getRecentFailures($repo, 48))
        ->toThrow(RequestException::class);
});

test('skips a job whose log has expired instead of abandoning the repository scan', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    // GitHub expires job logs long before it forgets the run, so a 404 here
    // is routine for older runs — the rest of the scan must survive it.
    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 51, 'name' => 'Go tests', 'conclusion' => 'failure'],
                ['id' => 52, 'name' => 'Tests (Pest)', 'conclusion' => 'failure'],
            ],
        ]),
        'api.github.com/repos/acme/app/actions/jobs/51/logs' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/app/actions/jobs/52/logs' => Http::response(pestJobLog()),
    ]);

    $failures = app(ActionsBuildScanner::class)->getRecentFailures($repo, 48);

    expect($failures)->toHaveCount(1)
        ->and($failures->first()->testName)->toBe('Tests\Dash\Feature\OneOffInvoiceIssuanceTest…');
});

test('still raises when a log is refused for a reason other than expiry', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/app',
        'default_branch' => 'master',
        'ci_system' => 'github_actions',
    ]);

    // A 403 means the App lacks Actions: Read. Reporting "no flaky tests" for
    // a permissions problem is exactly the silent failure this scanner had.
    Http::fake([
        'api.github.com/repos/acme/app/actions/runs?*' => Http::response(failedRunResponse()),
        'api.github.com/repos/acme/app/actions/runs/900/jobs*' => Http::response([
            'jobs' => [
                ['id' => 61, 'name' => 'Tests (Pest)', 'conclusion' => 'failure'],
            ],
        ]),
        'api.github.com/repos/acme/app/actions/jobs/61/logs' => Http::response(['message' => 'Forbidden'], 403),
    ]);

    expect(fn () => app(ActionsBuildScanner::class)->getRecentFailures($repo, 48))
        ->toThrow(RequestException::class);
});
