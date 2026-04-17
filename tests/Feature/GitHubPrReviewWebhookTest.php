<?php

use App\Enums\TaskMode;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config()->set('yak.channels.github.webhook_secret', 'secret');
    config()->set('yak.channels.github.app_bot_login', 'yak-bot[bot]');
});

function signGhPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

it('enqueues a full review task for pull_request.opened on a non-draft PR', function () {
    Bus::fake();

    Repository::factory()->create([
        'slug' => 'geocodio/api',
        'is_active' => true,
        'pr_review_enabled' => true,
        'git_url' => 'https://github.com/geocodio/api.git',
    ]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/1',
            'number' => 1,
            'title' => 'T',
            'body' => 'B',
            'draft' => false,
            'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'feat/x', 'sha' => 'aaa'],
            'base' => ['ref' => 'main', 'sha' => 'bbb'],
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $response = $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ]);

    $response->assertOk();

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

it('skips draft PRs', function () {
    Bus::fake();

    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/2',
            'draft' => true,
            'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 2, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    expect(YakTask::count())->toBe(0);
});

it('skips Yak-authored PRs', function () {
    Bus::fake();

    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/3',
            'draft' => false,
            'user' => ['login' => 'yak-bot[bot]'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 3, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    expect(YakTask::count())->toBe(0);
});

it('skips when the repo has pr_review_enabled = false', function () {
    Bus::fake();

    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => false]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/4',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 4, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    expect(YakTask::count())->toBe(0);
});

it('dedups same PR + head SHA', function () {
    Bus::fake();

    $repo = Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'external_id' => 'https://github.com/geocodio/api/pull/5',
        'pr_url' => 'https://github.com/geocodio/api/pull/5',
        'status' => 'pending',
        'context' => json_encode(['head_sha' => 'same-sha']),
    ]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/5',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'same-sha'],
            'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 5, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});
