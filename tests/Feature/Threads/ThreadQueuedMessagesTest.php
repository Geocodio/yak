<?php

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\QueuedMessages;
use App\Services\Threads\ThreadTurns;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';
require_once __DIR__ . '/../../Support/AppApiHelpers.php';

beforeEach(fn () => $this->withoutVite());

/** @return array{0: Thread, 1: User, 2: PendingSteeringMessage} */
function queuedListThread(string $text = 'Then update the docs'): array
{
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $author = User::factory()->create(['name' => 'Ada Author']);
    $thread->forceFill(['model' => 'sonnet', 'created_by_user_id' => $author->id, 'owner_user_id' => $author->id])->save();
    $message = app(ThreadTurns::class)->steer($thread, $text, $author, null, source: 'thread', isQueueOnly: true);

    return [$thread, $author, $message];
}

it('lists the undelivered queued messages with what the list needs', function () {
    [$thread, $author, $message] = queuedListThread();

    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->has('messages', 1)
        ->where('messages.0.id', "queued-message-{$message->id}")
        ->where('messages.0.text', 'Then update the docs')
        ->where('messages.0.mode', 'queue')
        ->where('messages.0.state', 'pending')
        ->where('messages.0.canEditText', true)
        ->where('composer.state', 'working'));
});

it('lets only the author edit the text of a queued message', function () {
    [$thread, , $message] = queuedListThread();
    $teammate = User::factory()->create();

    $this->actingAs($teammate)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('messages.0.canEditText', false));

    $this->actingAs($teammate)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['text' => 'hijacked'])
        ->assertSessionHas('error', QueuedMessages::NOT_YOUR_MESSAGE_SENTENCE);
    expect($message->fresh()->text)->toBe('Then update the docs');
});

it('switches the mode and edits the text of a queued message', function () {
    [$thread, $author, $message] = queuedListThread();

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['mode' => 'queue', 'text' => 'Then update the README'])
        ->assertRedirect(route('threads.show', $thread));
    expect($message->fresh())->text->toBe('Then update the README')->mode->toBe(SteeringMode::Queue);

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['text' => ''])
        ->assertSessionHasErrors();
    expect($message->fresh()->text)->toBe('Then update the README');
});

it('withdraws a queued message', function () {
    [$thread, $author, $message] = queuedListThread();

    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertRedirect(route('threads.show', $thread));

    expect(PendingSteeringMessage::query()->count())->toBe(0);
    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page->has('messages', 0));
});

it('refuses a viewer without drive rights and keeps the message', function () {
    [$thread, , $message] = queuedListThread();
    $thread->forceFill(['visibility' => 'private'])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['mode' => 'queue'])->assertForbidden();
    $this->actingAs($viewer)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertForbidden();
    $this->actingAs($viewer)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('composer.state', 'readonly')
        ->where('canDrive', false)
        ->where('messages.0.canEditText', false));

    expect($message->fresh())->not->toBeNull();
});

it('answers a queued message of another thread like one that does not exist', function () {
    [$thread, $author] = queuedListThread();
    [, , $foreign] = queuedListThread('Someone else');

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $foreign->id]), ['mode' => 'queue'])
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $foreign->id]))
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, 99999999]))
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);

    expect($foreign->fresh())->not->toBeNull();
});

it('refuses to change a message that was delivered, with the designed sentence, and leaves the list without it', function () {
    [$thread, $author, $message] = queuedListThread();
    $run = $thread->runs()->latest('id')->firstOrFail();
    ThreadTurnMessage::query()->create([
        'task_run_id' => $run->id,
        'uuid' => $message->uuid,
        'kind' => TurnMessageKind::Steer,
        'state' => TurnMessageState::Sent,
        'text' => $message->text,
        'pending_steering_message_id' => $message->id,
    ]);

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['text' => 'too late'])
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);

    expect($message->fresh()->text)->toBe('Then update the docs');
    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->has('messages', 1)
        ->where('messages.0.id', fn ($id) => str_starts_with($id, 'turn-message-')));
});

it('lists a message queued from a channel with its source and lets a driver switch and withdraw it, but not edit its text', function () {
    [$thread, $author] = queuedListThread();
    $slack = PendingSteeringMessage::queueFor($thread->headTask(), 'also bump the changelog', 'slack', authorName: 'Sam');
    $teammate = User::factory()->create();

    $this->actingAs($teammate)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->has('queuedMessages', 2)
        ->where('queuedMessages.1.id', $slack->id)
        ->where('queuedMessages.1.source', 'slack')
        ->where('queuedMessages.1.sourceLabel', 'Slack')
        ->where('queuedMessages.1.authorName', 'Sam')
        ->where('queuedMessages.1.canSteer', true)
        ->where('queuedMessages.1.canSwitch', true)
        ->where('queuedMessages.1.canRemove', true)
        ->where('queuedMessages.1.canEditText', false)
        ->where('queuedMessages.0.canEditText', false)
        ->where('messages', fn ($messages) => collect($messages)->every(fn ($message) => $message['text'] !== 'also bump the changelog')));

    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $this->actingAs($teammate)->patch(route('threads.queued-messages.update', [$thread, $slack->id]), ['mode' => 'steer'])->assertRedirect();
    expect($slack->fresh()->mode)->toBe(SteeringMode::Steer);

    $this->actingAs($teammate)->patch(route('threads.queued-messages.update', [$thread, $slack->id]), ['text' => 'rewritten'])
        ->assertSessionHas('error', QueuedMessages::NOT_YOUR_MESSAGE_SENTENCE);
    expect($slack->fresh()->text)->toBe('also bump the changelog');

    $this->actingAs($teammate)->delete(route('threads.queued-messages.destroy', [$thread, $slack->id]))->assertRedirect();
    expect(PendingSteeringMessage::query()->whereKey($slack->id)->exists())->toBeFalse();

    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->has('queuedMessages', 1)
        ->where('queuedMessages.0.canEditText', true));
});

it('lets a person who can drive switch and remove the queued message of another person', function () {
    [$thread, , $message] = queuedListThread();
    $other = User::factory()->create();

    $this->actingAs($other)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['mode' => 'queue'])->assertRedirect();
    $this->actingAs($other)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertRedirect();

    expect(PendingSteeringMessage::query()->count())->toBe(0);
});

it('gives a viewer without drive rights the list with every action off', function () {
    [$thread] = queuedListThread();
    PendingSteeringMessage::queueFor($thread->headTask(), 'from slack', 'slack');
    $thread->forceFill(['visibility' => 'private'])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->has('queuedMessages', 2)
        ->where('queuedMessages.0.canSwitch', false)
        ->where('queuedMessages.0.canRemove', false)
        ->where('queuedMessages.1.canSwitch', false)
        ->where('queuedMessages.1.canRemove', false));
});

it('answers a channel message of another thread with 404', function () {
    [$thread, $author] = queuedListThread();
    [$other] = queuedListThread('other');
    $foreign = PendingSteeringMessage::queueFor($other->headTask(), 'from slack', 'slack');

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $foreign->id]), ['mode' => 'queue'])
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $foreign->id]))
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, 99999999]))
        ->assertSessionHas('error', QueuedMessages::DELIVERED_SENTENCE);
});

it('keeps the web-only queued list out of the app API thread show', function () {
    [$thread, $author] = queuedListThread();
    PendingSteeringMessage::queueFor($thread->headTask(), 'from slack', 'slack');

    $this->getJson(appThreadPath($thread), phoneHeadersFor($author))->assertOk()->assertJsonMissingPath('queuedMessages');
});

it('refuses a text edit of a channel message on the web and app routes, even from the person it maps to, but lets its mode switch', function () {
    [$thread, $author] = queuedListThread();
    $slack = PendingSteeringMessage::queueFor($thread->headTask(), 'from slack', 'slack', authorName: $author->name, authorUserId: $author->id);
    $slack->forceFill(['uuid' => (string) Str::uuid()])->save();
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $slack->id]), ['text' => 'rewritten'])
        ->assertSessionHas('error', QueuedMessages::NOT_YOUR_MESSAGE_SENTENCE);
    $this->patchJson(appThreadPath($thread, "/queued-messages/{$slack->uuid}"), ['text' => 'rewritten'], phoneHeadersFor($author))->assertForbidden();
    expect($slack->fresh()->text)->toBe('from slack');

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $slack->id]), ['mode' => 'steer'])->assertRedirect();
    expect($slack->fresh()->mode)->toBe(SteeringMode::Steer);
    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('queuedMessages', fn ($queued) => collect($queued)->firstWhere('id', $slack->id)['canEditText'] === false));
});

it('keeps the source of a channel reply once it is steered into the turn', function () {
    [$thread, $author] = queuedListThread();
    $slack = PendingSteeringMessage::queueFor($thread->headTask(), 'from slack', 'slack', authorName: 'Sam');
    $slack->forceFill(['uuid' => (string) Str::uuid()])->save();
    $run = $thread->runs()->latest('id')->firstOrFail();
    ThreadTurnMessage::query()->create([
        'task_run_id' => $run->id, 'uuid' => $slack->uuid, 'kind' => TurnMessageKind::Steer, 'state' => TurnMessageState::Sent,
        'text' => $slack->text, 'pending_steering_message_id' => $slack->id,
    ]);

    $this->actingAs($author)->get(route('threads.show', $thread))->assertInertia(fn (Assert $page) => $page
        ->where('messages', fn ($messages) => collect($messages)->contains(fn ($message) => $message['text'] === 'from slack'
            && $message['via']['key'] === 'slack' && $message['via']['label'] === 'Slack' && $message['isReply'] === true && $message['authorName'] === 'Sam'))
        ->where('queuedMessages', fn ($queued) => collect($queued)->every(fn ($row) => $row['id'] !== $slack->id)));
});
