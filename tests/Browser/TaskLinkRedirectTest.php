<?php

use App\Enums\TurnMessageState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;

/**
 * A long thread whose follow-up task starts early, so its message needs a scroll to be seen.
 *
 * @return array{0: User, 1: Thread, 2: YakTask, 3: string}
 */
function longThreadWithFollowUp(): array
{
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $root = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the bug']);
    $rootRun = TaskRun::factory()->create(['yak_task_id' => $root->id, 'thread_id' => $thread->id, 'turn_state' => 'completed']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $rootRun->id, 'state' => TurnMessageState::Completed, 'created_at' => now()->subHours(3)]);

    $followUp = YakTask::factory()->create(['thread_id' => $thread->id, 'parent_task_id' => $root->id, 'description' => 'Also the logout']);
    $followUpRun = TaskRun::factory()->create(['yak_task_id' => $followUp->id, 'thread_id' => $thread->id, 'turn_state' => 'completed']);
    $message = ThreadTurnMessage::factory()->create(['task_run_id' => $followUpRun->id, 'state' => TurnMessageState::Completed, 'created_at' => now()->subHours(2)]);

    foreach (range(1, 30) as $number) {
        ThreadTurnMessage::factory()->steer()->create([
            'task_run_id' => $followUpRun->id,
            'state' => TurnMessageState::Completed,
            'text' => "Filler message {$number} " . str_repeat('padding ', 40),
            'created_at' => now()->subHour()->addMinutes($number),
        ]);
    }

    return [$user, $thread, $followUp, 'message-' . $message->uuid];
}

function elementIsInView(string $id): string
{
    return "(() => { const element = document.getElementById('{$id}'); if (element === null) { return false; } const box = element.getBoundingClientRect(); return box.top >= 0 && box.top < window.innerHeight; })()";
}

it('opens the message of a follow-up task from its old task link', function () {
    [$user, $thread, $followUp, $anchor] = longThreadWithFollowUp();
    $this->actingAs($user);

    visit("/tasks/{$followUp->public_id}")
        ->wait(2)
        ->assertPathIs("/threads/{$thread->public_id}")
        ->assertScript('window.location.hash', "#{$anchor}")
        ->assertScript(elementIsInView($anchor), true)
        ->assertNoJavaScriptErrors();
});

it('scrolls to the message named by an old turn fragment', function () {
    [$user, $thread, $followUp, $anchor] = longThreadWithFollowUp();
    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}")->wait(1);
    $page->script("window.location.hash = 'turn-{$followUp->public_id}'");

    $page->wait(2)
        ->assertScript(elementIsInView($anchor), true)
        ->assertNoJavaScriptErrors();
});

it('scrolls to the question card named by the old questions fragment', function () {
    [$user, $thread, $followUp] = longThreadWithFollowUp();
    YakTask::query()->whereKey($followUp->id)->update(['status' => 'awaiting_clarification']);
    $followUp->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $this->actingAs($user);

    visit("/threads/{$thread->public_id}#questions")
        ->wait(2)
        ->assertPresent('#card-question')
        ->assertScript(elementIsInView('card-question'), true)
        ->assertNoJavaScriptErrors();
});
