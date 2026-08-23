<?php

use App\Jobs\FlushFollowUpBatchJob;
use App\Models\FollowUpPendingComment;
use App\Models\GitHubInstallationToken;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
        'installation_id' => 99,
    ]);
    config()->set('yak.followup.github_prefixes', '/yak,@yak-bot[bot],yak:');
    config()->set('yak.followup.github_batch_window_seconds', 60);

    GitHubInstallationToken::create([
        'installation_id' => 99,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    (new ChannelServiceProvider(app()))->boot();
});

function signGhFollowUpPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

// ─── issue_comment ────────────────────────────────────────────────────────────

it('buffers a /yak issue_comment on a Yak task PR and dispatches FlushFollowUpBatchJob', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => [
                'html_url' => 'https://github.com/acme/web/pull/9',
            ],
        ],
        'comment' => [
            'id' => 42,
            'user' => ['login' => 'mathias'],
            'body' => '/yak please add tests',
        ],
        'repository' => ['full_name' => 'acme/web'],
        'installation' => ['id' => 99],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk()->assertJsonPath('ok', true);

    expect(FollowUpPendingComment::where('pr_url', 'https://github.com/acme/web/pull/9')->count())->toBe(1);

    $comment = FollowUpPendingComment::first();
    expect($comment->yak_task_id)->toBe($task->id)
        ->and($comment->body)->toBe('please add tests')
        ->and($comment->file)->toBeNull()
        ->and($comment->github_comment_id)->toBe(42);

    Bus::assertDispatched(FlushFollowUpBatchJob::class, fn ($job) => $job->prUrl === 'https://github.com/acme/web/pull/9');
});

it('skips a comment with no /yak prefix', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/9'],
        ],
        'comment' => [
            'id' => 43,
            'user' => ['login' => 'mathias'],
            'body' => 'looks good to me',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
});

it('skips a bot-authored issue_comment', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/9'],
        ],
        'comment' => [
            'id' => 44,
            'user' => ['login' => 'yak-bot[bot]'],
            'body' => '/yak do something',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
});

it('skips an issue_comment on a PR with no matching YakTask', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 99,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/99'],
        ],
        'comment' => [
            'id' => 45,
            'user' => ['login' => 'mathias'],
            'body' => '/yak please do this',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
});

it('skips an issue_comment on a plain issue (no pull_request key)', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 5,
            // No pull_request key — this is a plain issue comment
        ],
        'comment' => [
            'id' => 46,
            'user' => ['login' => 'mathias'],
            'body' => '/yak do something',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
});

// ─── pull_request_review_comment ─────────────────────────────────────────────

it('buffers a /yak pull_request_review_comment capturing file, line, and diff_hunk', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'pull_request' => [
            'html_url' => 'https://github.com/acme/web/pull/9',
            'number' => 9,
        ],
        'comment' => [
            'id' => 77,
            'user' => ['login' => 'mathias'],
            'body' => '/yak rename this variable',
            'path' => 'app/Foo.php',
            'line' => 42,
            'diff_hunk' => '@@ -1,3 +1,4 @@',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request_review_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk()->assertJsonPath('ok', true);

    expect(FollowUpPendingComment::count())->toBe(1);

    $comment = FollowUpPendingComment::first();
    expect($comment->yak_task_id)->toBe($task->id)
        ->and($comment->body)->toBe('rename this variable')
        ->and($comment->file)->toBe('app/Foo.php')
        ->and($comment->line)->toBe(42)
        ->and($comment->diff_hunk)->toBe('@@ -1,3 +1,4 @@')
        ->and($comment->github_comment_id)->toBe(77);

    Bus::assertDispatched(FlushFollowUpBatchJob::class, fn ($job) => $job->prUrl === 'https://github.com/acme/web/pull/9');
});

// ─── merged PR ───────────────────────────────────────────────────────────────

it('declines a /yak comment on a merged PR and does not buffer', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    YakTask::factory()->merged()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
        'pr_number' => 9,
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/9'],
        ],
        'comment' => [
            'id' => 50,
            'user' => ['login' => 'mathias'],
            'body' => '/yak please add more tests',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);

    // A decline comment should have been posted to the PR's issues comments endpoint.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/repos/acme/web/issues/9/comments'));
});

// ─── dedup: second comment on same PR does not re-dispatch the job ────────────

it('does not dispatch a second FlushFollowUpBatchJob when there is already a pending comment', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    // Seed an existing pending comment to simulate a previously batched item.
    FollowUpPendingComment::create([
        'yak_task_id' => $task->id,
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'body' => 'earlier comment',
        'github_comment_id' => 40,
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/9'],
        ],
        'comment' => [
            'id' => 41,
            'user' => ['login' => 'mathias'],
            'body' => '/yak one more thing',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(2);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
});

it('uses original_line when line is null in a pull_request_review_comment', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $task = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'pull_request' => [
            'html_url' => 'https://github.com/acme/web/pull/9',
            'number' => 9,
        ],
        'comment' => [
            'id' => 88,
            'user' => ['login' => 'mathias'],
            'body' => '/yak consider refactoring',
            'path' => 'src/core.php',
            'line' => null,
            'original_line' => 7,
            'diff_hunk' => '@@ -5,3 +5,4 @@',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request_review_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk()->assertJsonPath('ok', true);

    expect(FollowUpPendingComment::count())->toBe(1);

    $comment = FollowUpPendingComment::first();
    expect($comment->yak_task_id)->toBe($task->id)
        ->and($comment->body)->toBe('consider refactoring')
        ->and($comment->file)->toBe('src/core.php')
        ->and($comment->line)->toBe(7)
        ->and($comment->diff_hunk)->toBe('@@ -5,3 +5,4 @@')
        ->and($comment->github_comment_id)->toBe(88);

    Bus::assertDispatched(FlushFollowUpBatchJob::class, fn ($job) => $job->prUrl === 'https://github.com/acme/web/pull/9');
});

it('makes no external call when the comment is not for a Yak task', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 99,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/99'],
        ],
        'comment' => [
            'id' => 51,
            'user' => ['login' => 'mathias'],
            'body' => '/yak please implement feature',
        ],
        'repository' => ['full_name' => 'acme/web'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::count())->toBe(0);
    Bus::assertNotDispatched(FlushFollowUpBatchJob::class);
    Http::assertNothingSent();
});

it('captures the comment author on the buffered follow-up comment', function () {
    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 201)]);

    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/31',
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ]);

    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 31,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/31'],
        ],
        'comment' => [
            'id' => 43,
            'user' => ['login' => 'mathias'],
            'body' => '/yak please add tests',
        ],
        'repository' => ['full_name' => 'acme/web'],
        'installation' => ['id' => 99],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signGhFollowUpPayload($body),
    ])->assertOk();

    expect(FollowUpPendingComment::first()->author)->toBe('mathias');
});
