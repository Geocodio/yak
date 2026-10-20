<?php

use App\Channels\Slack\CoOwnerBlocks;
use App\Channels\Slack\InteractivityTracker;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Jobs\ApplyCoOwnerDecisionJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Jobs\SendNotificationJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const CO_OWNER_RESPONSE_URL = 'https://hooks.slack.test/actions/T1/1/abc';

function coOwnerClickBody(string $actionId, string $value, string $slackUserId): string
{
    return 'payload=' . urlencode((string) json_encode([
        'type' => 'block_actions',
        'user' => ['id' => $slackUserId],
        'response_url' => CO_OWNER_RESPONSE_URL,
        'actions' => [['action_id' => $actionId, 'value' => $value]],
    ]));
}

function clickCoOwnerButton(string $secret, string $actionId, string $value, string $slackUserId)
{
    $body = coOwnerClickBody($actionId, $value, $slackUserId);

    return test()->call('POST', '/webhooks/slack/interactive', content: $body, server: signSlackInteractivePayload($body, $secret));
}

function coOwnerFakes(string $role = 'write', array $extra = []): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + $extra + [
        CO_OWNER_RESPONSE_URL => Http::response('ok'),
        'api.github.com/user/4242' => Http::response(['login' => 'mhansen']),
        'api.github.com/repos/o/r/collaborators/*/permission' => Http::response(['role_name' => $role]),
        'api.github.com/repos/o/r/pulls?*' => Http::response([]),
        'api.github.com/repos/o/r/pulls' => Http::response(['number' => 7, 'html_url' => 'https://github.com/o/r/pull/7']),
        'api.github.com/repos/o/r/issues/*/labels' => Http::response([]),
        'api.github.com/repos/o/r/pulls/*/requested_reviewers' => Http::response([]),
        'api.github.com/repos/o/r/git/refs/*' => Http::response([], 204),
    ]);
}

function ephemeralSent(string $text): bool
{
    $sent = false;
    Http::assertSent(function ($request) use ($text, &$sent): bool {
        if ($request->url() === CO_OWNER_RESPONSE_URL && str_contains((string) $request['text'], $text) && $request['response_type'] === 'ephemeral') {
            $sent = true;
        }

        return true;
    });

    return $sent;
}

beforeEach(function () {
    InteractivityTracker::reset();
    $this->secret = enableSlackForInteractive();
    configureFakeGitHubApp();
    config()->set('yak.pr_title_writer.enabled', false);
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester', 'slack_user_id' => 'URITA']);
    $this->approver = User::factory()->withGitHub()->create(['name' => 'Mathias Hansen', 'slack_user_id' => 'UAPPROVER']);
    $this->task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'o-r',
        'branch_name' => 'yak/x',
        'source' => 'slack',
        'description' => 'Fix auth',
        'started_by_user_id' => $this->requester->id,
    ]);
    $this->request = CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]);
    coOwnerFakes();
});

it('queues the decision and answers at once', function () {
    Queue::fake();

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')
        ->assertOk()->assertJson(['ok' => true]);

    Queue::assertPushed(ApplyCoOwnerDecisionJob::class, fn (ApplyCoOwnerDecisionJob $job): bool => $job->requestId === $this->request->id
        && $job->slackUserId === 'UAPPROVER'
        && $job->action === CoOwnerBlocks::APPROVE_ACTION_ID
        && $job->responseUrl === CO_OWNER_RESPONSE_URL);
    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('ignores the link buttons that share the prefix', function () {
    Queue::fake();

    clickCoOwnerButton($this->secret, 'yak_link_open_task', (string) $this->request->id, 'UAPPROVER')->assertOk();

    Queue::assertNothingPushed();
});

it('approves from Slack', function () {
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Approved)
        ->and($this->task->fresh()->pr_url)->toBe('https://github.com/o/r/pull/7');
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job): bool => $job->event === 'resolved');
});

it('rejects from Slack', function () {
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::REJECT_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('sends the connect prompt to a user without GitHub and changes nothing', function () {
    $stranger = User::factory()->create(['slack_user_id' => 'UNOLINK']);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UNOLINK')->assertOk();

    expect(ephemeralSent('Connect GitHub to approve. Yak checks you have write access to o/r.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
    Http::assertSent(fn ($r): bool => $r->url() === CO_OWNER_RESPONSE_URL
        && $r['blocks'][1]['elements'][0]['text']['text'] === 'Connect GitHub'
        && str_starts_with($r['blocks'][1]['elements'][0]['action_id'], 'yak_link_')
        && str_contains($r['blocks'][1]['elements'][0]['url'], 'return=' . urlencode("/tasks/{$this->task->id}")));
    expect($stranger->exists)->toBeTrue();
});

it('sends the connect prompt to an unmapped Slack user', function () {
    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UGHOST')->assertOk();

    expect(ephemeralSent('Connect GitHub to approve.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('sends the no-access message and changes nothing', function () {
    coOwnerFakes('read');

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent("Your GitHub account {$this->approver->github_login} has no write access to o/r. Only people with write access can approve or reject."))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('answers a stale click with the resolved state', function () {
    Bus::fake([SendCoOwnerMessagesJob::class]);
    $this->request->update(['status' => CoOwnerRequestStatus::Rejected]);
    $this->task->update(['status' => TaskStatus::Cancelled]);
    Http::fake(['slack.com/api/*' => Http::response(['ok' => true, 'ts' => '1.2', 'channel' => 'C1'])]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent('Only visible to you: this request was already resolved, no changes were made.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Rejected);
});

it('reports a GitHub failure as an ephemeral message', function () {
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class]);
    coOwnerFakes('write', ['api.github.com/repos/o/r/pulls' => Http::response(['message' => 'Validation Failed'], 422)]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent('Approval did not go through. GitHub answered 422 "Validation Failed" when Yak tried to open the PR from yak/x. Nothing changed. If you no longer have write access to o/r, ask a repository admin. Otherwise press Approve again.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('reports a failed access check as an ephemeral message', function () {
    coOwnerFakes('write', ['api.github.com/repos/o/r/collaborators/*/permission' => Http::response([], 500)]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::APPROVE_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent('Yak could not check your access on GitHub.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});

it('lets only the requester withdraw from the thread', function () {
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class]);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::WITHDRAW_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent('Only Rita, who asked for this change, can withdraw it.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::WITHDRAW_ACTION_ID, (string) $this->request->id, 'URITA')->assertOk();

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('keeps the clarification buttons working', function () {
    Queue::fake();
    $task = YakTask::factory()->withClarificationQuestions([sampleQuestion('scope', ['acme/web', 'acme/api'])->toArray()])->create(['source' => 'slack']);
    $body = 'payload=' . urlencode((string) json_encode([
        'type' => 'block_actions',
        'user' => ['id' => 'U12345'],
        'actions' => [['action_id' => 'yak_clarify_0', 'value' => json_encode(['task' => $task->id, 'question' => 'scope', 'label' => 'acme/web'])]],
    ]));

    $this->call('POST', '/webhooks/slack/interactive', content: $body, server: signSlackInteractivePayload($body, $this->secret))->assertOk();

    Queue::assertPushed(RunYakJob::class, fn (RunYakJob $job): bool => $job->task->id === $task->id);
    Queue::assertNotPushed(ApplyCoOwnerDecisionJob::class);
});

it('lets a requester with no Yak account withdraw by their Slack identity', function () {
    Bus::fake([SendCoOwnerMessagesJob::class, SendNotificationJob::class]);
    $this->task->update(['started_by_user_id' => null, 'slack_user_id' => 'UGHOSTREQ']);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::WITHDRAW_ACTION_ID, (string) $this->request->id, 'UGHOSTREQ')->assertOk();

    expect($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Withdrawn)
        ->and($this->request->fresh()->decided_by_user_id)->toBeNull()
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('never names a placeholder when the requester is unknown', function () {
    $this->task->update(['started_by_user_id' => null, 'slack_user_id' => 'UGHOSTREQ']);

    clickCoOwnerButton($this->secret, CoOwnerBlocks::WITHDRAW_ACTION_ID, (string) $this->request->id, 'UAPPROVER')->assertOk();

    expect(ephemeralSent('Only the person who asked for this change can withdraw it.'))->toBeTrue()
        ->and($this->request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending);
});
