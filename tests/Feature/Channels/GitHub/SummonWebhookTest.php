<?php

use App\Enums\TaskMode;
use App\Jobs\HandlePullRequestSummonJob;
use App\Jobs\TriageReviewJob;
use App\Models\GitHubInstallationToken;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
        'installation_id' => 99,
    ]);
    config()->set('yak.followup.github_prefixes', '/yak,@yak-bot[bot],yak:');
    config()->set('yak.followup.github_review_triage_enabled', true);

    GitHubInstallationToken::create([
        'installation_id' => 99,
        'token' => 'test-token',
        'expires_at' => now()->addHour(),
    ]);

    (new ChannelServiceProvider(app()))->boot();

    Bus::fake();
    Http::fake(['api.github.com/*' => Http::response([], 200)]);
});

function signSummonPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postSummonReview(array $overrides = []): TestResponse
{
    $payload = array_replace_recursive([
        'action' => 'submitted',
        'review' => [
            'id' => 500,
            'state' => 'commented',
            'body' => 'A few thoughts.',
            'user' => ['login' => 'michael'],
        ],
        'pull_request' => [
            'number' => 9,
            'html_url' => 'https://github.com/acme/web/pull/9',
        ],
        'repository' => ['full_name' => 'acme/web'],
        'installation' => ['id' => 99],
    ], $overrides);
    $body = json_encode($payload);

    return test()->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'pull_request_review',
        'X-Hub-Signature-256' => signSummonPayload($body),
    ]);
}

function postSummonIssueComment(string $body): TestResponse
{
    $payload = [
        'action' => 'created',
        'issue' => [
            'number' => 9,
            'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/9'],
        ],
        'comment' => [
            'id' => 42,
            'user' => ['login' => 'michael'],
            'body' => $body,
        ],
        'repository' => ['full_name' => 'acme/web'],
        'installation' => ['id' => 99],
    ];
    $encoded = json_encode($payload);

    return test()->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => 'issue_comment',
        'X-Hub-Signature-256' => signSummonPayload($encoded),
    ]);
}

it('sends a review on a PR with no Yak task to the summon job', function () {
    $response = postSummonReview();

    $response->assertOk()->assertJsonPath('summon', true);
    Bus::assertDispatched(HandlePullRequestSummonJob::class, fn ($job): bool => $job->reviewId === 500
        && $job->prNumber === 9
        && $job->summonerLogin === 'michael');
    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('sends a review on a PR with a summon task to the summon job, not triage', function () {
    YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9', 'mode' => TaskMode::Fix, 'targets_external_pr' => true,
    ]);

    postSummonReview()->assertOk();

    Bus::assertDispatched(HandlePullRequestSummonJob::class);
    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('keeps triage for Yak-owned PRs', function () {
    YakTask::factory()->success()->create(['pr_url' => 'https://github.com/acme/web/pull/9', 'mode' => TaskMode::Fix]);

    postSummonReview()->assertOk();

    Bus::assertDispatched(TriageReviewJob::class);
    Bus::assertNotDispatched(HandlePullRequestSummonJob::class);
});

it('sends a prefixed top-level comment on a human PR to the summon job', function () {
    postSummonIssueComment('/yak add a test')->assertOk();

    Bus::assertDispatched(HandlePullRequestSummonJob::class, fn ($job): bool => $job->issueCommentId === 42
        && $job->issueCommentBody === '/yak add a test');
});

it('ignores an unprefixed top-level comment on a human PR', function () {
    postSummonIssueComment('looks good')->assertOk()->assertJsonPath('skipped', 'no yak prefix');

    Bus::assertNotDispatched(HandlePullRequestSummonJob::class);
});

it('ignores bot reviews on human PRs', function () {
    postSummonReview(['review' => ['user' => ['login' => 'other-bot[bot]', 'type' => 'Bot']]])->assertOk();

    Bus::assertNotDispatched(HandlePullRequestSummonJob::class);
});
