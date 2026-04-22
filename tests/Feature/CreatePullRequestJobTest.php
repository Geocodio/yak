<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
});

/*
|--------------------------------------------------------------------------
| PR Creation via GitHub App API
|--------------------------------------------------------------------------
*/

test('creates PR via GitHub API with installation token', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test_installation_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 42,
            'html_url' => 'https://github.com/org/my-repo/pull/42',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result("src/Auth.php\nsrc/Login.php"),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
        'default_branch' => 'main',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-123',
        'source' => 'slack',
        'description' => 'Fix login timeout bug',
        'result_summary' => 'Fixed the login timeout issue',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    $task->refresh();
    expect($task->pr_url)->toBe('https://github.com/org/my-repo/pull/42');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.github.com/repos/org/my-repo/pulls')
            && $request['head'] === 'yak/FIX-123'
            && $request['base'] === 'main';
    });
});

test('uses cached installation token when not expired', function () {
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_cached_token',
        'expires_at' => now()->addMinutes(30),
    ]);

    Http::fake([
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-CACHED',
        'source' => 'manual',
        'description' => 'Test cached token',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/pulls')
            && $request->hasHeader('Authorization', 'Bearer ghs_cached_token');
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/access_tokens');
    });
});

test('requests new installation token when cached token is expired', function () {
    GitHubInstallationToken::factory()->expired()->create([
        'installation_id' => 99999,
        'token' => 'ghs_old_expired_token',
    ]);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_fresh_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-EXPIRED',
        'source' => 'manual',
        'description' => 'Test expired token',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/access_tokens');
    });
});

test('caches installation token in database after request', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_new_cached',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-CACHE',
        'source' => 'manual',
        'description' => 'Test caching',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    $cached = GitHubInstallationToken::where('installation_id', 99999)->first();
    expect($cached)->not->toBeNull()
        ->and($cached->expires_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| PR Title Format
|--------------------------------------------------------------------------
*/

test('PR title uses Yak Fix prefix for fix mode tasks', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-TITLE',
        'source' => 'manual',
        'description' => 'Fix broken auth',
        'mode' => 'fix',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/pulls')
            && $request['title'] === 'Yak Fix: Fix broken auth';
    });
});

test('PR title uses Yak Research prefix for research mode tasks', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/RES-1',
        'source' => 'manual',
        'description' => 'Investigate memory leak',
        'mode' => 'research',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/pulls')
            && $request['title'] === 'Yak Research: Investigate memory leak';
    });
});

test('PR title truncation preserves multi-byte characters at the boundary', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    // 56 ASCII chars + 3-byte • (bullet) would land byte 57 — the old
    // substr(0, 57) boundary — inside the multi-byte sequence, leaving
    // an orphan 0xE2 that Guzzle's json_encode rejects.
    $description = str_repeat('a', 56) . '• followed by more text to push past 60';

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-MB',
        'source' => 'manual',
        'description' => $description,
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $title = $request['title'];

        return mb_check_encoding($title, 'UTF-8')
            && str_starts_with($title, 'Yak Fix: ')
            && str_ends_with($title, '...')
            && str_contains($title, '•');
    });
});

test('PR title truncates long descriptions', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $longDescription = str_repeat('A very long description that exceeds the limit ', 5);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-LONG',
        'source' => 'manual',
        'description' => $longDescription,
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/pulls')
            && str_starts_with($request['title'], 'Yak Fix: ')
            && str_ends_with($request['title'], '...');
    });
});

/*
|--------------------------------------------------------------------------
| PR Body Template
|--------------------------------------------------------------------------
*/

test('PR body includes source, repo, attempts, and result summary', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/test-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/test-repo',
        'path' => '/home/yak/repos/test-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/test-repo',
        'branch_name' => 'yak/FIX-BODY',
        'source' => 'linear',
        'external_id' => 'LIN-500',
        'external_url' => 'https://linear.app/team/LIN-500',
        'result_summary' => 'Refactored authentication flow',
        'attempts' => 2,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '**Source:** linear')
            && str_contains($body, '**Repository:** org/test-repo')
            && str_contains($body, '**Attempts:** 2')
            && str_contains($body, 'Refactored authentication flow')
            && str_contains($body, '**Task:** [LIN-500](https://linear.app/team/LIN-500)');
    });
});

test('PR body does not wrap the agent summary in a "What changed" heading', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $agentSummary = "## Summary\n\nAdded a foo.\n\n## Changes\n\n- **foo** — did the thing";

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-FORMAT',
        'source' => 'manual',
        'description' => 'Test format',
        'result_summary' => $agentSummary,
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) use ($agentSummary) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, $agentSummary)
            && ! str_contains($body, '### What changed')
            && ! str_contains($body, '## Yak Automated PR');
    });
});

test('PR body omits the Files changed section and Yak warning footer', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-TRIM',
        'source' => 'manual',
        'description' => 'Test trim',
        'result_summary' => '## Summary\n\nDid a thing.',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return ! str_contains($body, '### Files changed')
            && ! str_contains($body, 'generated by Yak')
            && ! str_contains($body, 'Review before merging');
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/compare/');
    });
});

/*
|--------------------------------------------------------------------------
| PR Body — Signed URLs for Screenshots and Videos
|--------------------------------------------------------------------------
*/

test('PR body includes screenshot signed URLs', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-SCREENSHOT',
        'source' => 'manual',
        'description' => 'Test screenshots',
        'attempts' => 1,
    ]);

    Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'before.png',
    ]);

    Artifact::factory()->screenshot()->create([
        'yak_task_id' => $task->id,
        'filename' => 'after.png',
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '### Screenshots')
            && str_contains($body, 'before.png')
            && str_contains($body, 'after.png')
            && str_contains($body, 'signature=');
    });
});

test('PR body prefers reviewer cut over raw webm when both exist', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-CUT',
        'source' => 'manual',
        'description' => 'Prefer the cut',
        'attempts' => 1,
    ]);

    Artifact::factory()->video()->create([
        'yak_task_id' => $task->id,
        'filename' => 'walkthrough.webm',
    ]);

    Artifact::factory()->videoCut()->create([
        'yak_task_id' => $task->id,
        'filename' => 'reviewer-cut.mp4',
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '### Video walkthrough')
            && str_contains($body, 'reviewer-cut.mp4')
            && ! str_contains($body, 'walkthrough.webm');
    });
});

test('PR body falls back to raw webm when no reviewer cut exists', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-NOCUT',
        'source' => 'manual',
        'description' => 'Fall back to raw webm',
        'attempts' => 1,
    ]);

    // Only a raw video, no reviewer cut.
    Artifact::factory()->video()->create([
        'yak_task_id' => $task->id,
        'filename' => 'walkthrough.webm',
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '### Video walkthrough')
            && str_contains($body, 'walkthrough.webm');
    });
});

test('PR body includes video walkthrough signed URLs', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 1,
            'html_url' => 'https://github.com/org/my-repo/pull/1',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-VIDEO',
        'source' => 'manual',
        'description' => 'Test video',
        'attempts' => 1,
    ]);

    Artifact::factory()->video()->create([
        'yak_task_id' => $task->id,
        'filename' => 'walkthrough.mp4',
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/pulls')) {
            return false;
        }

        $body = $request['body'];

        return str_contains($body, '### Video walkthrough')
            && str_contains($body, 'walkthrough.mp4')
            && str_contains($body, 'signature=');
    });
});

/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

test('applies yak label to every PR', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 5,
            'html_url' => 'https://github.com/org/my-repo/pull/5',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-LABEL',
        'source' => 'manual',
        'description' => 'Test labels',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/5/labels')
            && in_array('yak', $request['labels']);
    });
});

test('applies yak-large-change label when LOC exceeds threshold', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 7,
            'html_url' => 'https://github.com/org/my-repo/pull/7',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-LARGE',
        'source' => 'manual',
        'description' => 'Large change',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task, isLargeChange: true);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/7/labels')
            && in_array('yak', $request['labels'])
            && in_array('yak-large-change', $request['labels']);
    });
});

test('does not apply yak-large-change label for small changes', function () {
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_test',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 8,
            'html_url' => 'https://github.com/org/my-repo/pull/8',
        ]),
        'api.github.com/repos/*/issues/*/labels' => Http::response(['ok' => true]),
        'api.github.com/repos/*/compare/*' => Http::response(['files' => []]),
    ]);

    Process::fake([
        'git diff --name-only *' => Process::result(''),
    ]);

    Repository::factory()->create([
        'slug' => 'org/my-repo',
        'path' => '/home/yak/repos/my-repo',
    ]);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/FIX-SMALL',
        'source' => 'manual',
        'description' => 'Small change',
        'attempts' => 1,
    ]);

    $job = new CreatePullRequestJob($task, isLargeChange: false);
    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/issues/8/labels')
            && in_array('yak', $request['labels'])
            && ! in_array('yak-large-change', $request['labels']);
    });
});

/*
|--------------------------------------------------------------------------
| Job Queue Configuration
|--------------------------------------------------------------------------
*/

test('CreatePullRequestJob dispatches to default queue', function () {
    $task = YakTask::factory()->awaitingCi()->make();
    $job = new CreatePullRequestJob($task);

    expect($job->queue)->toBe('default');
});

/*
|--------------------------------------------------------------------------
| GitHub App JWT Auth
|--------------------------------------------------------------------------
*/

test('GitHubAppService generates valid JWT structure', function () {
    $service = new GitHubAppService;
    $jwt = $service->generateJwt();

    $parts = explode('.', $jwt);
    expect($parts)->toHaveCount(3);

    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    expect($header['alg'])->toBe('RS256')
        ->and($header['typ'])->toBe('JWT');

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    expect($payload['iss'])->toBe('12345')
        ->and($payload)->toHaveKeys(['iat', 'exp', 'iss']);
});

/*
|--------------------------------------------------------------------------
| CI System Detection
|--------------------------------------------------------------------------
*/

test('detectCiSystem returns drone when .drone.yml is present', function () {
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'api.github.com/repos/org/my-repo/contents/.drone.yml' => Http::response(['name' => '.drone.yml']),
    ]);

    $service = new GitHubAppService;
    expect($service->detectCiSystem(99999, 'org/my-repo'))->toBe('drone');
});

test('detectCiSystem returns github_actions when .github/workflows has files', function () {
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'api.github.com/repos/org/my-repo/contents/.drone.yml' => Http::response('', 404),
        'api.github.com/repos/org/my-repo/contents/.github/workflows' => Http::response([
            ['name' => 'ci.yml', 'type' => 'file'],
        ]),
    ]);

    $service = new GitHubAppService;
    expect($service->detectCiSystem(99999, 'org/my-repo'))->toBe('github_actions');
});

test('detectCiSystem returns none when no CI config is committed', function () {
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'api.github.com/repos/org/my-repo/contents/.drone.yml' => Http::response('', 404),
        'api.github.com/repos/org/my-repo/contents/.github/workflows' => Http::response('', 404),
    ]);

    $service = new GitHubAppService;
    expect($service->detectCiSystem(99999, 'org/my-repo'))->toBe('none');
});
