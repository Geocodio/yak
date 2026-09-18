<?php

use App\Enums\TaskMode;
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

function signGhReviewPayload(string $payload): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, 'secret');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postReview(array $overrides = []): TestResponse
{
    $payload = array_replace_recursive([
        'action' => 'submitted',
        'review' => [
            'id' => 500,
            'state' => 'commented',
            'body' => 'A few thoughts.',
            'user' => ['login' => 'alice'],
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
        'X-Hub-Signature-256' => signGhReviewPayload($body),
    ]);
}

function reviewedYakTask(array $overrides = []): YakTask
{
    return YakTask::factory()->success()->create(array_merge([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'repo' => 'acme/web',
        'branch_name' => 'yak/x',
    ], $overrides));
}

it('dispatches TriageReviewJob for a submitted review on a Yak PR', function () {
    $task = reviewedYakTask();

    postReview()->assertOk()->assertJsonPath('ok', true);

    Bus::assertDispatched(TriageReviewJob::class, fn (TriageReviewJob $job) => $job->taskId === $task->id
        && $job->reviewId === 500
        && $job->prNumber === 9
        && $job->reviewState === 'commented'
        && $job->reviewBody === 'A few thoughts.'
        && $job->reviewerLogin === 'alice');
});

it('ignores review actions other than submitted', function () {
    reviewedYakTask();

    postReview(['action' => 'dismissed'])->assertOk()->assertJsonPath('skipped', 'not a submitted review');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips a PR that Yak did not open', function () {
    postReview()->assertOk()->assertJsonPath('skipped', 'no yak task for pr');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips a review that Yak wrote itself', function () {
    reviewedYakTask();

    postReview(['review' => ['user' => ['login' => 'yak-bot[bot]']]])->assertOk()->assertJsonPath('skipped', 'yak authored review');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips a review from any bot account, regardless of login', function () {
    reviewedYakTask();

    postReview(['review' => ['user' => ['login' => 'some-other-bot[bot]', 'type' => 'Bot']]])
        ->assertOk()->assertJsonPath('skipped', 'yak authored review');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips a review from the bot login without its [bot] suffix', function () {
    reviewedYakTask();

    postReview(['review' => ['user' => ['login' => 'yak-bot']]])
        ->assertOk()->assertJsonPath('skipped', 'yak authored review');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips a closed PR', function () {
    reviewedYakTask(['pr_closed_at' => now()]);

    postReview()->assertOk()->assertJsonPath('skipped', 'pr not open');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('skips an approval with no text', function () {
    reviewedYakTask();

    postReview(['review' => ['state' => 'approved', 'body' => '  ']])->assertOk()->assertJsonPath('skipped', 'empty approval');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('still triages an approval that has a body', function () {
    reviewedYakTask();

    postReview(['review' => ['state' => 'approved', 'body' => 'One nit inline.']])->assertOk();

    Bus::assertDispatched(TriageReviewJob::class);
});

it('skips a Review-mode task with the PR url', function () {
    reviewedYakTask(['mode' => TaskMode::Review]);

    postReview()->assertOk()->assertJsonPath('skipped', 'no yak task for pr');

    Bus::assertNotDispatched(TriageReviewJob::class);
});

it('resolves the Fix root when a Fix root and a Review task share the PR url', function () {
    $fixRoot = reviewedYakTask();
    reviewedYakTask(['mode' => TaskMode::Review, 'created_at' => now()->subMinute()]);

    postReview()->assertOk()->assertJsonPath('ok', true);

    Bus::assertDispatched(TriageReviewJob::class, fn (TriageReviewJob $job) => $job->taskId === $fixRoot->id);
});

it('ignores reviews entirely when the flag is off', function () {
    config()->set('yak.followup.github_review_triage_enabled', false);
    reviewedYakTask();

    postReview()->assertOk()->assertJsonPath('skipped', 'review triage disabled');

    Bus::assertNotDispatched(TriageReviewJob::class);
});
