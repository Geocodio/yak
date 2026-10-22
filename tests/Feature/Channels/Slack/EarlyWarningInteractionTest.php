<?php

use App\Channels\Slack\CoOwnerBlocks;
use App\Channels\Slack\InteractivityTracker;
use App\Jobs\ApplyEarlyWarningChoiceJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const EARLY_WARNING_RESPONSE_URL = 'https://hooks.slack.test/actions/T1/2/def';

function earlyWarningBody(string $actionId, string $slackUserId, ?string $selectedUser = null): string
{
    $action = ['action_id' => $actionId, 'value' => '1'];
    if ($selectedUser !== null) {
        $action = ['action_id' => $actionId, 'type' => 'users_select', 'selected_user' => $selectedUser];
    }

    return 'payload=' . urlencode((string) json_encode([
        'type' => 'block_actions',
        'user' => ['id' => $slackUserId],
        'response_url' => EARLY_WARNING_RESPONSE_URL,
        'channel' => ['id' => 'C1'],
        'container' => ['message_ts' => '1.2'],
        'actions' => [$action],
    ]));
}

function clickEarlyWarning(string $secret, string $actionId, string $slackUserId, ?string $selectedUser = null)
{
    $body = earlyWarningBody($actionId, $slackUserId, $selectedUser);

    return test()->call('POST', '/webhooks/slack/interactive', content: $body, server: signSlackInteractivePayload($body, $secret));
}

function earlyWarningFakes(string $role = 'write'): void
{
    Http::swap(new Factory);
    Http::fake(gitHubTokenFake() + [
        EARLY_WARNING_RESPONSE_URL => Http::response('ok'),
        'api.github.com/user/*' => Http::response(['login' => 'picked']),
        'api.github.com/repos/o/r/collaborators/*/permission' => $role === 'error' ? Http::response([], 500) : Http::response(['role_name' => $role]),
        'slack.com/api/chat.update' => Http::response(['ok' => true]),
        'slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['real_name' => 'Stranger Danger', 'profile' => []]]),
    ]);
}

function earlyWarningEphemeral(string $text): bool
{
    $found = false;
    Http::assertSent(function ($request) use ($text, &$found): bool {
        if ($request->url() === EARLY_WARNING_RESPONSE_URL && $request['text'] === $text && $request['response_type'] === 'ephemeral') {
            $found = true;
        }

        return true;
    });

    return $found;
}

beforeEach(function () {
    InteractivityTracker::reset();
    Cache::flush();
    $this->secret = enableSlackForInteractive();
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
    configureFakeGitHubApp();
    $this->repository = Repository::factory()->create(['slug' => 'o-r', 'github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->requester = User::factory()->create(['name' => 'Rita Requester', 'slack_user_id' => 'URITA']);
    $this->coOwner = User::factory()->withGitHub(11, 'picked')->create(['name' => 'Casey Owner', 'slack_user_id' => 'UCASEY']);
    $this->task = YakTask::factory()->running()->create([
        'repo' => 'o-r',
        'source' => 'slack',
        'started_by_user_id' => $this->requester->id,
        'early_warning' => [
            'areas' => [['name' => 'Billing', 'risk' => 'critical']],
            'state' => 'shown',
            'slackChannel' => 'C1',
            'slackTs' => '1.2',
            'taggedAt' => null,
        ],
    ]);
    earlyWarningFakes();
});

function tagAction(YakTask $task): string
{
    return CoOwnerBlocks::TAG_ACTION_PREFIX . $task->id;
}

it('queues a pick with the picked user and the message location', function () {
    Queue::fake();

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk()->assertJson(['ok' => true]);

    Queue::assertPushed(ApplyEarlyWarningChoiceJob::class, fn (ApplyEarlyWarningChoiceJob $job): bool => $job->taskId === $this->task->id
        && $job->slackUserId === 'URITA'
        && $job->action === CoOwnerBlocks::TAG_ACTION_PREFIX
        && $job->selectedSlackUserId === 'UCASEY'
        && $job->responseUrl === EARLY_WARNING_RESPONSE_URL
        && $job->channel === 'C1'
        && $job->messageTs === '1.2');
});

it('queues a decline', function () {
    Queue::fake();

    clickEarlyWarning($this->secret, CoOwnerBlocks::DECLINE_ACTION_PREFIX . $this->task->id, 'URITA')->assertOk();

    Queue::assertPushed(ApplyEarlyWarningChoiceJob::class, fn (ApplyEarlyWarningChoiceJob $job): bool => $job->action === CoOwnerBlocks::DECLINE_ACTION_PREFIX && $job->selectedSlackUserId === null);
});

it('stores a valid pick and updates the message in place', function () {
    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();

    $fresh = $this->task->fresh();
    expect($fresh->tagged_co_owner_user_id)->toBe($this->coOwner->id)
        ->and($fresh->early_warning['state'])->toBe('tagged');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'chat.update') && $request['channel'] === 'C1' && $request['ts'] === '1.2');
});

it('replaces an earlier pick with a second one', function () {
    $other = User::factory()->withGitHub(12, 'other')->create(['name' => 'Olive Other', 'slack_user_id' => 'UOLIVE']);

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();
    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UOLIVE')->assertOk();

    expect($this->task->fresh()->tagged_co_owner_user_id)->toBe($other->id);
});

it('records a decline and updates the message', function () {
    clickEarlyWarning($this->secret, CoOwnerBlocks::DECLINE_ACTION_PREFIX . $this->task->id, 'URITA')->assertOk();

    expect($this->task->fresh()->early_warning['state'])->toBe('declined');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'chat.update'));
});

it('refuses a pick from someone who is not the requester', function () {
    User::factory()->create(['name' => 'Nosy Parker', 'slack_user_id' => 'UNOSY']);

    clickEarlyWarning($this->secret, tagAction($this->task), 'UNOSY', 'UCASEY')->assertOk();

    expect(earlyWarningEphemeral('Only Rita can pick the co-owner for this request.'))->toBeTrue()
        ->and($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('refuses a pick that is not a Yak user and names them from Slack', function () {
    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'USTRANGER')->assertOk();

    expect(earlyWarningEphemeral('Stranger Danger has not linked GitHub in Yak, so Yak cannot check their access. Pick someone else, or ask them to connect GitHub on their profile.'))->toBeTrue()
        ->and($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('refuses a pick who has not linked GitHub', function () {
    User::factory()->create(['name' => 'Lena Unlinked', 'slack_user_id' => 'ULENA']);

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'ULENA')->assertOk();

    expect(earlyWarningEphemeral('Lena Unlinked has not linked GitHub in Yak, so Yak cannot check their access. Pick someone else, or ask them to connect GitHub on their profile.'))->toBeTrue();
});

it('refuses a pick without write access', function () {
    earlyWarningFakes('read');

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();

    expect(earlyWarningEphemeral('Casey Owner has no write access to o/r. Only people with write access can co-own a change.'))->toBeTrue()
        ->and($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('refuses a pick when the access check fails', function () {
    earlyWarningFakes('error');

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();

    expect(earlyWarningEphemeral('Yak could not check your access on GitHub. Try again in a minute.'))->toBeTrue();
});

it('refuses a pick once the task is held', function () {
    $this->task->update(['status' => 'awaiting_co_owner']);

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();

    expect(earlyWarningEphemeral('This request already moved on, so the pick was not saved.'))->toBeTrue()
        ->and($this->task->fresh()->tagged_co_owner_user_id)->toBeNull();
});

it('refuses a decline once the conversation has a co-owner request', function () {
    CoOwnerRequest::factory()->create(['task_id' => $this->task->id, 'repository_id' => $this->repository->id]);

    clickEarlyWarning($this->secret, CoOwnerBlocks::DECLINE_ACTION_PREFIX . $this->task->id, 'URITA')->assertOk();

    expect(earlyWarningEphemeral('This request already moved on, so the pick was not saved.'))->toBeTrue()
        ->and($this->task->fresh()->early_warning['state'])->toBe('shown');
});

it('refuses a pick once the task is finished', function () {
    $this->task->update(['status' => 'success']);

    clickEarlyWarning($this->secret, tagAction($this->task), 'URITA', 'UCASEY')->assertOk();

    expect(earlyWarningEphemeral('This request already moved on, so the pick was not saved.'))->toBeTrue();
});
