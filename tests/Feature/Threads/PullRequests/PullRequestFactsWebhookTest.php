<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
        'installation_id' => 0,
    ]);
    config()->set('yak.followup.github_review_triage_enabled', true);
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
    Http::fake();

    Repository::factory()->create(['slug' => 'acme-app', 'github_full_name' => 'acme/app', 'ci_system' => 'github_actions']);
    $this->row = ThreadRepository::factory()->for(Thread::factory()->create())->withPullRequest(42)->create(['repo' => 'acme-app']);
});

function postFactsWebhook(string $event, array $payload): TestResponse
{
    return test()->postJson('/webhooks/github', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
        'X-GitHub-Event' => $event,
    ]);
}

function pullRequestPayload(string $action, array $overrides = []): array
{
    return [
        'action' => $action,
        'pull_request' => array_merge(['number' => 42, 'draft' => false, 'head' => ['sha' => 'abc'], 'created_at' => '2026-11-24T10:00:00Z'], $overrides),
        'repository' => ['full_name' => 'acme/app'],
    ];
}

function reviewPayload(string $action, string $state, string $login = 'Mathias'): array
{
    return [
        'action' => $action,
        'review' => ['id' => 9, 'state' => $state, 'body' => '', 'commit_id' => 'abc', 'user' => ['login' => $login, 'type' => 'User']],
        'pull_request' => ['number' => 42],
        'repository' => ['full_name' => 'acme/app'],
    ];
}

it('records draft and head sha on opened', function () {
    postFactsWebhook('pull_request', pullRequestPayload('opened', ['draft' => true]));

    $row = $this->row->refresh();
    expect($row->pull_request_is_draft)->toBeTrue()
        ->and($row->pull_request_head_sha)->toBe('abc')
        ->and($row->pull_request_opened_at->toIso8601String())->toBe('2026-11-24T10:00:00+00:00');
});

it('clears draft on ready_for_review', function () {
    $this->row->forceFill(['pull_request_is_draft' => true])->save();

    postFactsWebhook('pull_request', pullRequestPayload('ready_for_review'));

    expect($this->row->refresh()->pull_request_is_draft)->toBeFalse();
});

it('sets draft on converted_to_draft', function () {
    postFactsWebhook('pull_request', pullRequestPayload('converted_to_draft', ['draft' => true]));

    expect($this->row->refresh()->pull_request_is_draft)->toBeTrue();
});

it('updates head sha on synchronize', function () {
    postFactsWebhook('pull_request', pullRequestPayload('synchronize', ['head' => ['sha' => 'def']]));

    expect($this->row->refresh()->pull_request_head_sha)->toBe('def');
});

it('stores an approval keyed by lowercased login', function () {
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED'));

    expect($this->row->refresh()->pull_request_reviews)->toBe(['mathias' => ['state' => 'approved', 'commitSha' => 'abc']]);
});

it('a comment does not replace an approval', function () {
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED'));
    $comment = reviewPayload('submitted', 'COMMENTED');
    $comment['review']['commit_id'] = 'def';
    postFactsWebhook('pull_request_review', $comment);

    expect($this->row->refresh()->pull_request_reviews['mathias'])->toBe(['state' => 'approved', 'commitSha' => 'abc']);
});

it('changes requested replaces an approval', function () {
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED'));
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'CHANGES_REQUESTED'));

    expect($this->row->refresh()->pull_request_reviews['mathias']['state'])->toBe('changes_requested');
});

it('dismissed marks the review dismissed', function () {
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED'));
    postFactsWebhook('pull_request_review', reviewPayload('dismissed', 'DISMISSED'));

    expect($this->row->refresh()->pull_request_reviews['mathias']['state'])->toBe('dismissed');
});

it('ignores yak bot reviews', function () {
    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED', 'yak-bot[bot]'));

    expect($this->row->refresh()->pull_request_reviews)->toBeNull();
});

it('stores reviews when review triage is disabled', function () {
    config()->set('yak.followup.github_review_triage_enabled', false);

    postFactsWebhook('pull_request_review', reviewPayload('submitted', 'APPROVED'));

    expect($this->row->refresh()->pull_request_reviews)->toHaveKey('mathias');
});
