<?php

use App\DataTransferObjects\ResolvedAccount;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Support\ContractFixture;

require_once __DIR__ . '/../../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../../Support/ThreadTurnsHelpers.php';

const APP_SEND_KEY = '0b9c3e0e-6d5c-4d7e-9b7a-2f5d1c6a9e11';

/** @return array{0: Thread, 1: User} */
function idleThreadForApp(): array
{
    fakeTurnCollaborators()['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread] = threadWithTask();
    $user = User::factory()->create(['name' => 'Test person']);
    $thread->forceFill(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id])->save();

    return [$thread, $user];
}

/** @return array{0: Thread, 1: User} */
function runningThreadForApp(): array
{
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $user = User::factory()->create(['name' => 'Test person']);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id])->save();

    return [$thread, $user];
}

it('starts a turn and returns the message uuid', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = idleThreadForApp();

    $response = $this->post(appThreadPath($thread, '/messages'), [
        'message' => 'Retry the upload with backoff',
        'send_key' => APP_SEND_KEY,
        'attachments' => [UploadedFile::fake()->image('shot.png')],
        'attachment_refs' => ['Image #1'],
    ], phoneHeadersFor($user) + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'sent');

    $promptUuid = TaskRun::where('thread_id', $thread->id)->latest('id')->value('prompt_uuid');
    expect($response->json('uuid'))->toBe($promptUuid)
        ->and(ThreadTurnMessage::where('uuid', $promptUuid)->first()->attachments)->toHaveCount(1);
    ContractFixture::assertMatches('app', 'thread-message-sent', $response->json(), ['uuid']);
});

it('queues after the turn when asked', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = runningThreadForApp();
    $headers = phoneHeadersFor($user);

    $response = $this->postJson(appThreadPath($thread, '/messages'), ['message' => 'Then update the docs', 'send_key' => APP_SEND_KEY, 'delivery' => 'after_turn'], $headers)
        ->assertOk()->assertJsonPath('status', 'queued');

    $pending = PendingSteeringMessage::where('text', 'Then update the docs')->firstOrFail();
    expect($response->json('uuid'))->toBe($pending->uuid);
    $this->getJson(appThreadPath($thread), $headers)->assertOk()
        ->assertJsonFragment(['uuid' => $pending->uuid, 'state' => 'pending']);
    ContractFixture::assertMatches('app', 'thread-message-queued', $response->json(), ['uuid']);
});

it('returns the new thread id for clear', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user] = idleThreadForApp();
    app(ClaudeAccountResolver::class)->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(ClaudeAccount::factory()->system()->create(), 'sonnet'));

    $response = $this->postJson(appThreadPath($thread, '/messages'), ['message' => '/clear', 'send_key' => APP_SEND_KEY], phoneHeadersFor($user))->assertOk();

    expect($response->json())->toHaveKeys(['status', 'uuid', 'threadId'])
        ->and($response->json('status'))->toBe('sent')
        ->and($response->json('uuid'))->toBeNull()
        ->and($response->json('threadId'))->toHaveLength(8);
    ContractFixture::assertMatches('app', 'thread-message-clear', $response->json(), ['threadId']);
});

it('repeats safely over HTTP', function () {
    [$thread, $user] = runningThreadForApp();
    $headers = phoneHeadersFor($user);
    $body = ['message' => 'Then update the docs', 'send_key' => APP_SEND_KEY, 'delivery' => 'after_turn'];

    $first = $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertOk()->json();
    $second = $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertOk()->json();

    expect($second)->toBe($first)
        ->and(PendingSteeringMessage::where('text', 'Then update the docs')->count())->toBe(1);
});

it('answers each refusal with 409 and its code', function () {
    [$thread, $user] = idleThreadForApp();
    $headers = phoneHeadersFor($user);
    $body = ['message' => 'Hello', 'send_key' => APP_SEND_KEY];

    $thread->forceFill(['status' => ThreadStatus::Closed])->save();
    $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertStatus(409)->assertJsonPath('error', 'thread_closed');

    $thread->forceFill(['status' => ThreadStatus::Open, 'blocked_reason' => ThreadBlockReason::WaitingForRepository])->save();
    $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertStatus(409)
        ->assertJsonPath('error', 'thread_blocked')
        ->assertJsonPath('details.reason', 'waiting_for_repository');

    $thread->forceFill(['blocked_reason' => null, 'owner_side' => ThreadOwnerSide::Device])->save();
    $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertStatus(409)->assertJsonPath('error', 'thread_owned_by_device');
});

it('validates the message', function (array $body, string $field) {
    [$thread, $user] = idleThreadForApp();

    $this->postJson(appThreadPath($thread, '/messages'), $body, phoneHeadersFor($user))
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    'no send key' => [['message' => 'Hello'], 'send_key'],
    'bad send key' => [['message' => 'Hello', 'send_key' => 'nope'], 'send_key'],
    'bad delivery' => [['message' => 'Hello', 'send_key' => APP_SEND_KEY, 'delivery' => 'later'], 'delivery'],
    'empty message' => [['message' => '', 'send_key' => APP_SEND_KEY], 'message'],
    'long message' => [['message' => str_repeat('a', 20001), 'send_key' => APP_SEND_KEY], 'message'],
]);

it('lets a share recipient read but not send', function () {
    [$thread, $owner] = runningThreadForApp();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(appThreadPath($thread, '/messages'), ['message' => 'Hello', 'send_key' => APP_SEND_KEY], phoneHeadersFor($recipient))
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(PendingSteeringMessage::count())->toBe(0);
});

it('answers a hidden thread like a missing one', function () {
    $hidden = privateThreadOf(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());
    $body = ['message' => 'Hello', 'send_key' => APP_SEND_KEY];

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/messages', $body, $headers)->assertNotFound();
    $this->postJson(appThreadPath($hidden, '/messages'), $body, $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $body = ['message' => 'Hello', 'send_key' => APP_SEND_KEY];

    $this->postJson(appThreadPath($thread, '/messages'), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson(appThreadPath($thread, '/messages'), $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->postJson(appThreadPath($thread, '/messages'), $body, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
