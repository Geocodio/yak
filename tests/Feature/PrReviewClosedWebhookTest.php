<?php

use App\Enums\TaskMode;
use App\Models\PrReview;
use App\Models\YakTask;

beforeEach(function () {
    config()->set('yak.channels.github.webhook_secret', 'secret');
});

function signClosedPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

it('updates pr_reviews.pr_closed_at and pr_merged_at on pull_request.closed merged', function () {
    $prUrl = 'https://github.com/geocodio/api/pull/100';

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => $prUrl,
    ]);

    $review = PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'pr_url' => $prUrl,
        'pr_number' => 100,
        'repo' => 'geocodio/api',
    ]);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => $prUrl,
            'merged' => true,
            'merged_at' => '2026-04-17T00:00:00Z',
            'closed_at' => '2026-04-17T00:00:00Z',
        ],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signClosedPayload($body),
    ])->assertOk();

    $review->refresh();
    expect($review->pr_closed_at)->not->toBeNull()
        ->and($review->pr_merged_at)->not->toBeNull();
});

it('sets only pr_closed_at when merged=false', function () {
    $prUrl = 'https://github.com/geocodio/api/pull/101';

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'pr_url' => $prUrl,
    ]);

    $review = PrReview::factory()->create([
        'yak_task_id' => $task->id,
        'pr_url' => $prUrl,
        'pr_number' => 101,
        'repo' => 'geocodio/api',
    ]);

    $payload = [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => $prUrl,
            'merged' => false,
            'closed_at' => '2026-04-17T00:00:00Z',
        ],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signClosedPayload($body),
    ])->assertOk();

    $review->refresh();
    expect($review->pr_closed_at)->not->toBeNull()
        ->and($review->pr_merged_at)->toBeNull();
});
