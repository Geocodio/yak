<?php

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadSharing;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ContractFixture;

require_once __DIR__ . '/../../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../../Support/ThreadTurnsHelpers.php';

/** @return array{0: Thread, 1: User, 2: PendingSteeringMessage} */
function threadWithQueuedMessage(string $text = 'Then update the docs'): array
{
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $user = User::factory()->create(['name' => 'Test person']);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id])->save();
    $message = app(ThreadTurns::class)->steer($thread, $text, $user, null, source: 'thread', isQueueOnly: true);

    return [$thread, $user, $message];
}

function queuedMessagePath(Thread $thread, string $uuid): string
{
    return appThreadPath($thread, "/queued-messages/{$uuid}");
}

it('switches a queued message to steer and edits its text', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    [$thread, $user, $message] = threadWithQueuedMessage();
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $headers = phoneHeadersFor($user);

    $response = $this->patchJson(queuedMessagePath($thread, $message->uuid), ['mode' => 'steer', 'text' => 'Then update the README'], $headers)
        ->assertOk()->assertJson(['status' => 'updated']);

    expect($message->fresh())->text->toBe('Then update the README')->mode->toBe(SteeringMode::Steer);
    $thread->forceFill(['runner_state' => RunnerState::Busy])->save();
    $this->getJson(appThreadPath($thread), $headers)->assertOk()
        ->assertJsonFragment(['uuid' => $message->uuid, 'text' => 'Then update the README', 'mode' => 'steer', 'state' => 'pending']);
    ContractFixture::assertMatches('app', 'queued-message-updated', $response->json());
});

it('hands a message switched to steer to the running turn', function () {
    [$thread, $user, $message] = threadWithQueuedMessage();
    $headers = phoneHeadersFor($user);
    $this->mock(ThreadCommands::class)->shouldReceive('steer')->once();

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['mode' => 'steer'], $headers)->assertOk()->assertJson(['status' => 'updated']);
    expect(ThreadTurnMessage::where('uuid', $message->uuid)->exists())->toBeTrue();

    [$otherThread, $otherUser, $notReady] = threadWithQueuedMessage('Another note');
    $otherThread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $this->patchJson(queuedMessagePath($otherThread, $notReady->uuid), ['mode' => 'steer'], phoneHeadersFor($otherUser))->assertOk();

    expect(ThreadTurnMessage::where('uuid', $notReady->uuid)->exists())->toBeFalse()
        ->and($notReady->fresh()->mode)->toBe(SteeringMode::Steer);
});

it('withdraws a queued message', function () {
    Storage::fake('artifacts');
    [$thread, $user, $message] = threadWithQueuedMessage();
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $message->id]);
    Storage::disk('artifacts')->put($attachment->disk_path, 'png');
    $headers = phoneHeadersFor($user);

    $this->deleteJson(queuedMessagePath($thread, $message->uuid), [], $headers)->assertNoContent();

    expect(PendingSteeringMessage::find($message->id))->toBeNull()
        ->and(TaskAttachment::find($attachment->id))->toBeNull();
    Storage::disk('artifacts')->assertMissing($attachment->disk_path);
    $this->deleteJson(queuedMessagePath($thread, $message->uuid), [], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');
});

it('refuses to change a message that was already delivered', function () {
    [$thread, $user, $message] = threadWithQueuedMessage();
    $headers = phoneHeadersFor($user);
    $this->mock(ThreadCommands::class)->shouldReceive('steer')->once();
    expect(app(SteeringDelivery::class)->deliver($thread, $message))->toBeTrue();

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['text' => 'Something else'], $headers)
        ->assertStatus(409)->assertJsonPath('error', 'message_delivered')->assertJsonPath('message', 'Yak already has that message.');
    $this->deleteJson(queuedMessagePath($thread, $message->uuid), [], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');

    expect($message->fresh()->text)->toBe('Then update the docs');
    $this->getJson(appThreadPath($thread), $headers)->assertOk()->assertJsonFragment(['uuid' => $message->uuid, 'text' => 'Then update the docs']);
});

it('validates the edit', function (array $body) {
    [$thread, $user, $message] = threadWithQueuedMessage();

    $this->patchJson(queuedMessagePath($thread, $message->uuid), $body, phoneHeadersFor($user))->assertStatus(422);
    expect($message->fresh()->text)->toBe('Then update the docs');
})->with([
    'nothing' => [[]],
    'bad mode' => [['mode' => 'later']],
    'empty text' => [['text' => '']],
    'long text' => [['text' => str_repeat('a', 20001)]],
]);

it('does not find another thread\'s message', function () {
    [$thread, $user] = threadWithQueuedMessage();
    [, , $foreign] = threadWithQueuedMessage('Elsewhere');
    $headers = phoneHeadersFor($user);

    $this->patchJson(queuedMessagePath($thread, $foreign->uuid), ['text' => 'Hijacked'], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');
    $this->deleteJson(queuedMessagePath($thread, $foreign->uuid), [], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');

    expect($foreign->fresh()->text)->toBe('Elsewhere');
});

it('refuses on a closed thread', function () {
    [$thread, $user, $message] = threadWithQueuedMessage();
    $thread->forceFill(['status' => ThreadStatus::Closed])->save();

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['text' => 'Late'], phoneHeadersFor($user))->assertStatus(409)->assertJsonPath('error', 'thread_closed');
    $this->deleteJson(queuedMessagePath($thread, $message->uuid), [], phoneHeadersFor($user))->assertStatus(409)->assertJsonPath('error', 'thread_closed');
    expect(PendingSteeringMessage::find($message->id))->not->toBeNull();
});

it('refuses to steer a GitHub review', function () {
    [$thread, $user, $message] = threadWithQueuedMessage();
    $message->forceFill(['reviewer_login' => 'alice'])->save();

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['mode' => 'steer'], phoneHeadersFor($user))
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed')
        ->assertJsonPath('details.errors.mode.0', 'A GitHub review waits for the follow-up, so the reviewer is asked to look again.');

    expect($message->fresh()->mode)->toBe(SteeringMode::Queue);
});

it('answers message_delivered on a thread with no tasks', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $uuid = '7f2c1d9e-3b4a-4c5d-8e6f-0a1b2c3d4e5f';
    $headers = phoneHeadersFor($user);

    $this->patchJson(queuedMessagePath($thread, $uuid), ['text' => 'x'], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');
    $this->deleteJson(queuedMessagePath($thread, $uuid), [], $headers)->assertStatus(409)->assertJsonPath('error', 'message_delivered');
});

it('keeps a message pending while a teleport is open', function () {
    [$thread, $user, $message] = threadWithQueuedMessage();
    Teleport::factory()->create(['thread_id' => $thread->id, 'user_id' => $user->id]);
    $this->mock(ThreadCommands::class)->shouldNotReceive('steer');

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['mode' => 'steer'], phoneHeadersFor($user))->assertOk();

    expect($message->fresh()->mode)->toBe(SteeringMode::Steer)
        ->and(ThreadTurnMessage::where('uuid', $message->uuid)->exists())->toBeFalse();
});

it('answers 404 for a uuid that is not a uuid', function () {
    [$thread, $user] = threadWithQueuedMessage();

    $this->patchJson(appThreadPath($thread, '/queued-messages/123'), ['text' => 'x'], phoneHeadersFor($user))->assertNotFound();
});

it('lets a share recipient read but not change queued messages', function () {
    [$thread, $owner, $message] = threadWithQueuedMessage();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);
    $headers = phoneHeadersFor($recipient);

    $this->patchJson(queuedMessagePath($thread, $message->uuid), ['text' => 'Nope'], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->deleteJson(queuedMessagePath($thread, $message->uuid), [], $headers)->assertForbidden();
    expect($message->fresh()->text)->toBe('Then update the docs');
});

it('answers a hidden thread like a missing one', function () {
    $hidden = privateThreadOf(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());
    $uuid = '7f2c1d9e-3b4a-4c5d-8e6f-0a1b2c3d4e5f';

    $missing = $this->patchJson('/api/app/v1/threads/zzzzzzzz/queued-messages/' . $uuid, ['text' => 'x'], $headers)->assertNotFound();
    $this->patchJson(queuedMessagePath($hidden, $uuid), ['text' => 'x'], $headers)->assertNotFound()->assertExactJson($missing->json());
    $missingDelete = $this->deleteJson('/api/app/v1/threads/zzzzzzzz/queued-messages/' . $uuid, [], $headers)->assertNotFound();
    $this->deleteJson(queuedMessagePath($hidden, $uuid), [], $headers)->assertNotFound()->assertExactJson($missingDelete->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $path = queuedMessagePath($thread, '7f2c1d9e-3b4a-4c5d-8e6f-0a1b2c3d4e5f');

    $this->deleteJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->deleteJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->deleteJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
