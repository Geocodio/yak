<?php

use App\Enums\TurnMessageState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;

/**
 * The pure merge is exposed as window.__mergeConversation for these checks.
 */
function threadMergeScript(string $messages, string $readUuids, string $items): string
{
    return "JSON.stringify(window.__mergeConversation({$messages}, {$readUuids}, {$items}).map(e => e.type === 'person' ? e.message.uuid + (e.pending ? '?' : '') : e.item.key))";
}

function threadMergePage(): mixed
{
    test()->actingAs(User::factory()->create());
    $thread = Thread::factory()->create();

    return visit("/threads/{$thread->id}");
}

$person = "{id:'m-a', uuid:'a', text:'a', authorName:'Ana', authorInitials:'A', model:null, state:'pending', sentAt:'2026-01-01T00:00:00Z', readAfterSequence:null, attachmentIds:[]}";
$event = fn (int $n) => "{kind:'text', key:'i{$n}', sequence:{$n}, role:'assistant', text:'t'}";

it('never renders a message twice when the same uuid is replayed', function () use ($person) {
    threadMergePage()->assertScript(threadMergeScript("[{$person},{$person}]", "['a','a','a']", '[]'), '["a"]');
});

it('keeps a message read once it was read', function () use ($person) {
    $script = "(() => { const track = window.__createReadTracker(); const messages = [{$person}]; track(['a']); const afterReplay = track([]); return JSON.stringify(window.__mergeConversation(messages, afterReplay, []).map(e => e.message.uuid + (e.pending ? '?' : ''))); })()";
    threadMergePage()->assertScript($script, '["a"]');
});

it('replays the full history after reconnect without duplicates', function () use ($event) {
    $items = '[' . implode(',', array_map($event, [1, 2, 3, 4, 5, 1, 2, 3, 4, 5])) . ']';
    threadMergePage()->assertScript(threadMergeScript('[]', '[]', $items), '["i1","i2","i3","i4","i5"]');
});

it('places a read message at readAfterSequence and a live read one after the answer', function () use ($person, $event) {
    $anchored = str_replace(["state:'pending'", 'readAfterSequence:null'], ["state:'read'", 'readAfterSequence:2'], $person);
    $live = str_replace(["'a'", "'m-a'"], ["'b'", "'m-b'"], $person);
    $items = '[' . implode(',', array_map($event, [1, 2, 3])) . ']';
    threadMergePage()->assertScript(threadMergeScript("[{$anchored},{$live}]", "['b']", $items), '["i1","i2","a","i3","b"]');
});

it('keeps an unread message greyed at the end', function () use ($person, $event) {
    threadMergePage()->assertScript(threadMergeScript("[{$person}]", '[]', '[' . $event(1) . ']'), '["i1","a?"]');
});

it('shows a message sent while Claude works greyed', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the bug']);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'running']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Queued]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="thread-message-pending"]')
        ->assertNoJavaScriptErrors();
});
