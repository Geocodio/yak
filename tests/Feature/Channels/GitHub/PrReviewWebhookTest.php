<?php

use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);

    (new ChannelServiceProvider(app()))->boot();
});

function signGhPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

it('handles pull_request events at the legacy /webhooks/ci/github URL', function () {
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
            'html_url' => 'https://github.com/geocodio/api/pull/99',
            'number' => 99, 'title' => '', 'body' => '',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    expect(YakTask::where('mode', TaskMode::Review)->count())->toBe(1);
});

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

it('triggers on ready_for_review', function () {
    Bus::fake();
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'ready_for_review',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/10',
            'draft' => false, 'user' => ['login' => 'm'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 10, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    $task = YakTask::where('mode', TaskMode::Review)->first();
    expect(json_decode($task->context, true)['review_scope'])->toBe('full');
});

it('triggers full review on reopened', function () {
    Bus::fake();
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'reopened',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/11',
            'draft' => false, 'user' => ['login' => 'm'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 11, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    $task = YakTask::where('mode', TaskMode::Review)->first();
    expect(json_decode($task->context, true)['review_scope'])->toBe('full');
});

it('triggers incremental review on synchronize when prior exists', function () {
    Bus::fake();
    $repo = Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $priorTask = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo->slug,
        'external_id' => 'https://github.com/geocodio/api/pull/20',
        'pr_url' => 'https://github.com/geocodio/api/pull/20',
    ]);

    PrReview::factory()->create([
        'yak_task_id' => $priorTask->id,
        'pr_url' => 'https://github.com/geocodio/api/pull/20',
        'commit_sha_reviewed' => 'old-sha',
    ]);

    $payload = [
        'action' => 'synchronize',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/20',
            'draft' => false, 'user' => ['login' => 'm'],
            'head' => ['ref' => 'x', 'sha' => 'new-sha'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 20, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    $task = YakTask::where('mode', TaskMode::Review)->latest()->first();
    $ctx = json_decode($task->context, true);
    expect($ctx['review_scope'])->toBe('incremental')
        ->and($ctx['incremental_base_sha'])->toBe('old-sha');
});

it('falls back to full on synchronize when no prior review exists', function () {
    Bus::fake();
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'synchronize',
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/21',
            'draft' => false, 'user' => ['login' => 'm'],
            'head' => ['ref' => 'x', 'sha' => 'new-sha'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 21, 'title' => '', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    $task = YakTask::where('mode', TaskMode::Review)->first();
    $ctx = json_decode($task->context, true);
    expect($ctx['review_scope'])->toBe('full')
        ->and($ctx['incremental_base_sha'])->toBeNull();
});

it('triggers a full review when the configured trigger label is added', function () {
    Bus::fake();
    config()->set('yak.pr_review.trigger_label', 'yak-review');
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'labeled',
        'label' => ['name' => 'yak-review'],
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/30',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 30, 'title' => 'T', 'body' => '',
        ],
        'repository' => ['full_name' => 'geocodio/api'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGhPayload($body),
    ])->assertOk();

    $task = YakTask::where('mode', TaskMode::Review)->first();
    expect($task)->not->toBeNull()
        ->and(json_decode($task->context, true)['review_scope'])->toBe('full');
});

it('ignores labels that do not match the trigger label', function () {
    Bus::fake();
    config()->set('yak.pr_review.trigger_label', 'yak-review');
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => true]);

    $payload = [
        'action' => 'labeled',
        'label' => ['name' => 'bug'],
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/31',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 31, 'title' => '', 'body' => '',
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

it('respects pr_review_enabled = false even when the trigger label is added', function () {
    Bus::fake();
    config()->set('yak.pr_review.trigger_label', 'yak-review');
    Repository::factory()->create(['slug' => 'geocodio/api', 'is_active' => true, 'pr_review_enabled' => false]);

    $payload = [
        'action' => 'labeled',
        'label' => ['name' => 'yak-review'],
        'pull_request' => [
            'html_url' => 'https://github.com/geocodio/api/pull/32',
            'draft' => false, 'user' => ['login' => 'mathias'],
            'head' => ['ref' => 'x', 'sha' => 'a'], 'base' => ['ref' => 'main', 'sha' => 'b'],
            'number' => 32, 'title' => '', 'body' => '',
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
