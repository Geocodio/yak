<?php

use App\Channels\GitHub\AppService;
use App\Jobs\Threads\StartRepositoryTurnJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function sendSiblingGitHub(string $event, array $payload): TestResponse
{
    config()->set('yak.followup.github_review_triage_enabled', $event === 'pull_request_review');

    return test()->postJson('/webhooks/github', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
        'X-GitHub-Event' => $event,
    ]);
}

function siblingFeedbackPayload(string $shape, string $repo = 'acme/web', string $body = '/yak rename the field', string $login = 'sam', string $type = 'User', int $userId = 1001): array
{
    $user = ['login' => $login, 'id' => $userId, 'type' => $type];
    $repository = ['full_name' => $repo];

    return ['sender' => ['id' => $userId, 'login' => $login]] + match ($shape) {
        'comment' => [
            'action' => 'created',
            'issue' => ['number' => 188, 'pull_request' => ['html_url' => "https://github.com/{$repo}/pull/188"]],
            'comment' => ['id' => 5, 'body' => $body, 'user' => $user],
            'repository' => $repository,
        ],
        'review' => [
            'action' => 'submitted',
            'review' => ['id' => 9, 'state' => 'commented', 'body' => $body, 'user' => $user],
            'pull_request' => ['number' => 188, 'html_url' => "https://github.com/{$repo}/pull/188"],
            'repository' => $repository,
        ],
        'review_comment' => [
            'action' => 'created',
            'pull_request' => ['number' => 188, 'html_url' => "https://github.com/{$repo}/pull/188"],
            'comment' => ['id' => 6, 'body' => $body, 'path' => 'a.php', 'line' => 3, 'user' => $user],
            'repository' => $repository,
        ],
    };
}

function siblingEvent(string $shape): string
{
    return ['comment' => 'issue_comment', 'review' => 'pull_request_review', 'review_comment' => 'pull_request_review_comment'][$shape];
}

function fakeSiblingPermission(string $login, int $status, array $body = []): void
{
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        "api.github.com/repos/acme/web/collaborators/{$login}/permission" => Http::response($body, $status),
        'api.github.com/*' => Http::response([], 200),
    ]);
}

function siblingPermissionRequests(): int
{
    return Http::recorded(fn ($request): bool => str_contains($request->url(), '/collaborators/'))->count();
}

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => $privateKey,
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
        'installation_id' => 42,
    ]);
    config()->set('yak.followup.github_prefixes', '/yak');
    (new ChannelServiceProvider(app()))->boot();
    app()->forgetInstance(AppService::class);
    Cache::flush();
    Queue::fake();

    Repository::factory()->create(['slug' => 'acme-api', 'github_full_name' => 'acme/api', 'ci_system' => 'github_actions']);
    Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'acme/web', 'ci_system' => 'github_actions']);

    $this->thread = Thread::factory()->create();
    $this->task = YakTask::factory()->awaitingCi()->create(['thread_id' => $this->thread->id, 'repo' => 'acme-api', 'branch_name' => 'yak/x', 'pr_url' => 'https://github.com/acme/api/pull/188']);
    ThreadRepository::factory()->for($this->thread)->withPullRequest(188)->create(['repo' => 'acme-api', 'branch' => 'yak/x', 'pull_request_url' => 'https://github.com/acme/api/pull/188']);
    $this->web = ThreadRepository::factory()->for($this->thread)->changes()->withPullRequest(188)->create(['repo' => 'acme-web', 'branch' => 'yak/x', 'pull_request_url' => 'https://github.com/acme/web/pull/188']);
});

it('starts nothing for an outsider on any sibling feedback shape', function (string $shape) {
    fakeSiblingPermission('sam', 200, ['role_name' => 'read', 'user' => ['permissions' => ['push' => false]]]);

    sendSiblingGitHub(siblingEvent($shape), siblingFeedbackPayload($shape))->assertOk()->assertJson(['skipped' => 'commenter lacks write access']);

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/reactions') || str_contains($request->url(), '/comments'));
})->with(['comment', 'review', 'review_comment']);

it('starts one turn for a collaborator with write on any sibling feedback shape', function (string $shape) {
    fakeSiblingPermission('sam', 200, ['role_name' => 'write']);

    sendSiblingGitHub(siblingEvent($shape), siblingFeedbackPayload($shape))->assertOk()->assertJson(['dispatched' => true]);

    Queue::assertPushed(StartRepositoryTurnJob::class, 1);
})->with(['comment', 'review', 'review_comment']);

it('never starts a turn for a bot or unknown sender', function (string $login, string $type, int $status) {
    fakeSiblingPermission($login, $status);

    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', login: $login, type: $type))->assertOk();

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
})->with([
    'bot type' => ['dependabot', 'Bot', 200],
    'empty login' => ['', 'User', 200],
    'not a collaborator' => ['sam', 'User', 404],
    'github error' => ['sam', 'User', 500],
]);

it('does not cache a failed permission lookup', function () {
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/web/collaborators/sam/permission' => Http::sequence()->push([], 500)->push(['role_name' => 'write'], 200),
        'api.github.com/*' => Http::response([], 200),
    ]);
    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment'))->assertOk();

    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment'))->assertOk()->assertJson(['dispatched' => true]);
});

it('skips non-permission cases the same way for the primary and the sibling repo', function (string $body, string $type, string $reason) {
    fakeSiblingPermission('sam', 200, ['role_name' => 'write']);

    foreach (['acme/api', 'acme/web'] as $repo) {
        sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', $repo, $body, type: $type))->assertOk()->assertJson(['skipped' => $reason]);
    }
})->with([
    'no prefix' => ['looks good', 'User', 'no yak prefix'],
    'bot author' => ['/yak do it', 'Bot', 'yak authored comment'],
]);

it('skips a closed pull request the same way for the primary and the sibling repo', function () {
    fakeSiblingPermission('sam', 200, ['role_name' => 'write']);
    $this->web->forceFill(['pull_request_state' => 'merged'])->save();
    $this->task->forceFill(['pr_merged_at' => now()])->save();

    foreach (['acme/api', 'acme/web'] as $repo) {
        sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', $repo))->assertOk()->assertJson(['skipped' => 'pr not open']);
    }
});

it('makes no permission request for an unprefixed outsider comment', function () {
    fakeSiblingPermission('sam', 200, ['role_name' => 'read']);

    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', body: 'looks good'))->assertOk()->assertJson(['skipped' => 'no yak prefix']);

    expect(siblingPermissionRequests())->toBe(0);
});

it('checks again when the same login has a different user id', function () {
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/acme/web/collaborators/sam/permission' => Http::sequence()->push(['role_name' => 'write'], 200)->push(['role_name' => 'read', 'user' => ['permissions' => ['push' => false]]], 200),
        'api.github.com/*' => Http::response([], 200),
    ]);

    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', userId: 1001))->assertOk()->assertJson(['dispatched' => true]);
    sendSiblingGitHub('issue_comment', siblingFeedbackPayload('comment', userId: 2002))->assertOk()->assertJson(['skipped' => 'commenter lacks write access']);

    expect(siblingPermissionRequests())->toBe(2);
});

it('is false without a numeric user id', function () {
    fakeSiblingPermission('sam', 200, ['role_name' => 'write']);

    $payload = siblingFeedbackPayload('comment', userId: 0);

    sendSiblingGitHub('issue_comment', $payload)->assertOk()->assertJson(['skipped' => 'commenter lacks write access']);

    expect(siblingPermissionRequests())->toBe(0);
});
