<?php

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Str;

require_once __DIR__ . '/../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../Support/ThreadTurnsHelpers.php';

/**
 * A thread with a running turn and one message waiting in its queue, written by the signed-in person.
 *
 * @return array{0: Thread, 1: User, 2: PendingSteeringMessage}
 */
function threadWithWaitingMessage(string $text = 'add docs afterwards', SteeringMode $mode = SteeringMode::Queue): array
{
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $user = User::factory()->create();
    // The runner is still starting, so a message switched to steer stays waiting instead of reaching a turn.
    $thread->forceFill(['model' => 'sonnet', 'runner_state' => RunnerState::Starting, 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id])->save();
    $message = app(ThreadTurns::class)->steer($thread, $text, $user, null, source: 'thread', isQueueOnly: true);
    $message->update(['mode' => $mode]);

    return [$thread, $user, $message];
}

it('lists a message waiting for Yak above the composer, and nowhere else', function () {
    [$thread, $user, $message] = threadWithWaitingMessage();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'add docs afterwards')
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'Queued')
        ->assertCount('[data-testid="queued-message-' . $message->id . '"]', 1)
        ->assertDontSeeIn('[data-testid="thread-conversation-scroller"]', 'add docs afterwards')
        ->assertNoJavaScriptErrors();
});

it('switches a waiting message between queued and steered', function () {
    [$thread, $user, $message] = threadWithWaitingMessage();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click("[data-testid=\"queued-message-mode-{$message->id}\"]")
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'data-mode', 'steer')
        ->assertSeeIn("[data-testid=\"queued-message-{$message->id}\"]", 'Steering')
        ->click("[data-testid=\"queued-message-mode-{$message->id}\"]")
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'data-mode', 'queue');

    expect($message->fresh()->mode)->toBe(SteeringMode::Queue);
});

it('offers steering only while Yak works on the thread', function () {
    $user = User::factory()->create();
    $task = YakTask::factory()->create();
    $thread = Thread::query()->findOrFail($task->thread_id);
    $message = PendingSteeringMessage::create(['root_task_id' => $task->id, 'text' => 'wait for the next turn', 'source' => 'thread', 'mode' => 'queue', 'uuid' => (string) Str::uuid(), 'author_user_id' => $user->id, 'author_name' => $user->name]);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'wait for the next turn')
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'aria-disabled', 'true')
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'data-mode', 'queue');
});

it('the X removes a waiting message', function () {
    [$thread, $user, $message] = threadWithWaitingMessage('never mind this');
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click("[data-testid=\"queued-message-remove-{$message->id}\"]")
        ->assertMissing('[data-testid="thread-queued-messages"]');

    expect(PendingSteeringMessage::count())->toBe(0);
});

it('edits a waiting message in its row', function () {
    [$thread, $user, $message] = threadWithWaitingMessage('rename the column', SteeringMode::Steer);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click("[data-testid=\"queued-message-edit-{$message->id}\"]")
        ->assertValue("[data-testid=\"queued-message-input-{$message->id}\"]", 'rename the column')
        ->fill("[data-testid=\"queued-message-input-{$message->id}\"]", 'rename the column to slug')
        ->click("[data-testid=\"queued-message-save-{$message->id}\"]")
        ->assertMissing("[data-testid=\"queued-message-input-{$message->id}\"]")
        ->assertSeeIn("[data-testid=\"queued-message-{$message->id}\"]", 'rename the column to slug')
        ->assertNoJavaScriptErrors();

    expect($message->fresh())->text->toBe('rename the column to slug')->mode->toBe(SteeringMode::Steer);
});

it('cancelling an edit leaves the message as it was', function () {
    [$thread, $user, $message] = threadWithWaitingMessage('keep this text');
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click("[data-testid=\"queued-message-edit-{$message->id}\"]")
        ->fill("[data-testid=\"queued-message-input-{$message->id}\"]", 'something else')
        ->click("[data-testid=\"queued-message-cancel-{$message->id}\"]")
        ->assertMissing("[data-testid=\"queued-message-input-{$message->id}\"]")
        ->assertSeeIn("[data-testid=\"queued-message-{$message->id}\"]", 'keep this text');

    expect($message->fresh()->text)->toBe('keep this text');
});

it('up arrow in an empty composer edits the latest waiting message', function () {
    [$thread, $user] = threadWithWaitingMessage('older note');
    $latest = app(ThreadTurns::class)->steer($thread, 'latest note', $user, null, source: 'thread', isQueueOnly: true);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-composer-input"]')
        ->keys('[data-testid="thread-composer-input"]', 'ArrowUp')
        ->assertValue("[data-testid=\"queued-message-input-{$latest->id}\"]", 'latest note')
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'older note');
});

it('shows the list read-only to someone who cannot drive the thread, and without edit for another author', function () {
    [$thread, , $message] = threadWithWaitingMessage();
    $thread->forceFill(['visibility' => 'private'])->save();
    $viewer = User::factory()->create();
    ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);
    $this->actingAs($viewer);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="thread-queued-messages"]', 'add docs afterwards')
        ->assertMissing("[data-testid=\"queued-message-remove-{$message->id}\"]")
        ->assertMissing("[data-testid=\"queued-message-edit-{$message->id}\"]")
        ->assertAttribute("[data-testid=\"queued-message-mode-{$message->id}\"]", 'aria-disabled', 'true');
});

it('keeps the composer on a phone screen with the list above it', function () {
    [$thread, $user] = threadWithWaitingMessage();
    foreach (range(1, 6) as $number) {
        app(ThreadTurns::class)->steer($thread, "note {$number}", $user, null, source: 'thread', isQueueOnly: true);
    }
    $this->actingAs($user);

    visit(route('threads.show', $thread))->on()->mobile()
        ->assertVisible('[data-testid="thread-composer-input"]')
        ->assertScript('document.querySelector(\'[data-testid="thread-queued-messages"]\').getBoundingClientRect().height <= 168', true)
        ->assertNoJavaScriptErrors();
});
