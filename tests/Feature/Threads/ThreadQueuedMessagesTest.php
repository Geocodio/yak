<?php

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

it('answers a queued message of another thread with 404', function () {
    [$thread, $author] = queuedListThread();
    [, , $foreign] = queuedListThread('Someone else');

    $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $foreign->id]), ['mode' => 'queue'])->assertNotFound();
    $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $foreign->id]))->assertNotFound();

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
