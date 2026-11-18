<?php

use App\Enums\SteeringMode;
use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadCommands;
use App\Support\TaskPageUrl;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a waiting message switches between queued and steered', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($task, 'also handle IPv6', 'dashboard');

    $this->patch(route('threads.queued-messages.update', [$task->thread, $message->id]), ['mode' => 'steer'])
        ->assertRedirect(TaskPageUrl::forOrLog($task));

    expect($message->fresh()->mode)->toBe(SteeringMode::Steer);

    $this->patch(route('threads.queued-messages.update', [$task->thread, $message->id]), ['mode' => 'queue']);

    expect($message->fresh()->mode)->toBe(SteeringMode::Queue);
});

test('a GitHub review cannot be steered, so its reviewer is still asked to look again', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($task, 'please rename', 'github_review', reviewerLogin: 'alice');

    $this->patch(route('threads.queued-messages.update', [$task->thread, $message->id]), ['mode' => 'steer'])
        ->assertRedirect(TaskPageUrl::forOrLog($task))
        ->assertSessionHas('error', 'A GitHub review waits for the follow-up, so the reviewer is asked to look again.');

    expect($message->fresh()->mode)->toBe(SteeringMode::Queue);
});

test('the mode must be queue or steer', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($task, 'note', 'dashboard');

    $this->patch(route('threads.queued-messages.update', [$task->thread, $message->id]), ['mode' => 'shout'])
        ->assertSessionHasErrors('mode');
});

test('withdrawing a message deletes it and its files', function () {
    Storage::fake('artifacts');
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($task, 'see [Image #1]', 'dashboard');
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $message->id]);
    Storage::disk('artifacts')->put($attachment->disk_path, 'png');

    $this->delete(route('threads.queued-messages.destroy', [$task->thread, $message->id]))
        ->assertRedirect(TaskPageUrl::forOrLog($task));

    expect(PendingSteeringMessage::find($message->id))->toBeNull()
        ->and(TaskAttachment::find($attachment->id))->toBeNull();
    Storage::disk('artifacts')->assertMissing($attachment->disk_path);
});

test('a message on a follow-up is reachable through any run in the conversation', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success]);
    $child = YakTask::factory()->create(['parent_task_id' => $root->id, 'status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($child, 'note', 'dashboard');

    $this->delete(route('threads.queued-messages.destroy', [$root->thread, $message->id]));

    expect(PendingSteeringMessage::count())->toBe(0);
});

test('a message Yak already has cannot be withdrawn or switched', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->delete(route('threads.queued-messages.destroy', [$task->thread, 999]))
        ->assertRedirect(TaskPageUrl::forOrLog($task))
        ->assertSessionHas('error', 'Yak already has that message.');

    $this->patch(route('threads.queued-messages.update', [$task->thread, 999]), ['mode' => 'steer'])
        ->assertSessionHas('error', 'Yak already has that message.');
});

test('switching to steer during a running turn hands the message to that turn', function () {
    [$thread] = runningTurnFor();
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    $message = PendingSteeringMessage::queueFor($task, 'also handle IPv6', 'dashboard');
    $this->mock(ThreadCommands::class)->shouldReceive('steer')->once();

    $this->patch(route('threads.queued-messages.update', [$task->thread, $message->id]), ['mode' => 'steer'])
        ->assertRedirect(TaskPageUrl::forOrLog($task))
        ->assertSessionMissing('error');

    expect(ThreadTurnMessage::where('pending_steering_message_id', $message->id)->first()?->uuid)->not->toBeEmpty();
});
