<?php

use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PUSH_PR_URL = 'https://github.com/acme/app/pull/7';

beforeEach(function () {
    configureFakeGitHubApp();
    config()->set('yak.channels.github.webhook_secret', 'secret');
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
    Http::fake(gitHubTokenFake() + ['api.github.com/*' => Http::response([])]);
    $this->repository = Repository::factory()->create([
        'slug' => 'app',
        'github_full_name' => 'acme/app',
        'github_repo_id' => 555,
    ]);
});

function pushReview(string $event, array $attributes = []): PrReview
{
    return PrReview::factory()->create([
        'yak_task_id' => YakTask::factory()->create(['mode' => TaskMode::Review]),
        'repo' => 'acme/app',
        'pr_number' => 7,
        'pr_url' => PUSH_PR_URL,
        'github_review_id' => 4242,
        'risk_assessment' => ['event' => $event],
    ] + $attributes);
}

function sendSynchronize(string $headSha = 'sha-new'): void
{
    $payload = [
        'action' => 'synchronize',
        'repository' => ['id' => 555, 'full_name' => 'acme/app'],
        'pull_request' => [
            'number' => 7,
            'html_url' => PUSH_PR_URL,
            'title' => 'Change',
            'user' => ['login' => 'dev'],
            'head' => ['sha' => $headSha, 'ref' => 'feature'],
            'base' => ['sha' => 'base-sha', 'ref' => 'main'],
        ],
    ];

    test()->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
    ])->assertOk();
}

function dismissalRequests(): int
{
    return Http::recorded(fn ($request) => str_ends_with($request->url(), '/reviews/4242/dismissals'))->count();
}

function enqueuedReviews(): int
{
    return YakTask::where('mode', TaskMode::Review)->where('external_id', PUSH_PR_URL)->where('status', 'pending')->count();
}

it('dismisses a standing approval and enqueues a full review on push', function () {
    $review = pushReview('APPROVE');

    sendSynchronize();

    expect(dismissalRequests())->toBe(1)
        ->and($review->fresh()->dismissed_at)->not->toBeNull()
        ->and(enqueuedReviews())->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/dismissals')
        && $request['message'] === 'New commits since Yak approved. Reviewing again.');
});

it('does nothing when the latest Yak review was a comment', function () {
    pushReview('COMMENT');

    sendSynchronize();

    expect(dismissalRequests())->toBe(0)->and(enqueuedReviews())->toBe(0);
});

it('does nothing when the approval is already dismissed', function () {
    pushReview('APPROVE', ['dismissed_at' => now()]);

    sendSynchronize();

    expect(dismissalRequests())->toBe(0)->and(enqueuedReviews())->toBe(0);
});

it('enqueues one review for two pushes of the same head', function () {
    pushReview('APPROVE');

    sendSynchronize('sha-new');
    sendSynchronize('sha-new');

    expect(enqueuedReviews())->toBe(1);
});

it('leaves dismissed_at null but still enqueues a review when GitHub rejects the dismissal', function () {
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/acme/app/pulls/7/reviews/4242/dismissals' => Http::response([], 422)]);
    $review = pushReview('APPROVE');

    sendSynchronize();

    expect($review->fresh()->dismissed_at)->toBeNull()->and(enqueuedReviews())->toBe(1);
});
