<?php

use App\Enums\RunnerState;
use App\Enums\TurnMessageState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventPublisher;

/**
 * The pure merge is exposed as window.__mergeConversation for these checks.
 */
function threadMergeScript(string $messages, string $readUuids, string $items, string $readSequences = '{}'): string
{
    return "JSON.stringify(window.__mergeConversation({$messages}, {$readUuids}, {$items}, {$readSequences}).map(e => e.type === 'person' ? e.message.uuid + (e.pending ? '?' : '') : e.item.key))";
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

it('places a live read message where Claude read it', function () use ($person, $event) {
    $items = '[' . implode(',', array_map($event, [1, 2, 3])) . ']';
    threadMergePage()->assertScript(threadMergeScript("[{$person}]", "['a']", $items, '{a: 2}'), '["i1","i2","a","i3"]');
});

it('keeps a second result in the same turn', function () {
    $results = "[{kind:'result', key:'result:4', sequence:4, role:'assistant', text:'one'}, {kind:'result', key:'result:7', sequence:7, role:'assistant', text:'two'}]";
    threadMergePage()->assertScript(threadMergeScript('[]', '[]', $results), '["result:4","result:7"]');
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
        ->assertSeeIn('[data-testid="thread-message-pending"]', 'Not read yet')
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=\"thread-message-pending\"]')).opacity", '1')
        ->assertNoJavaScriptErrors();
});

it('renders step rows with a chevron, the title block and the model chip', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'Fix the login redirect']);
    $publisher = app(ThreadEventPublisher::class);
    $publisher->append($thread->id, 'entry', ['role' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5']);
    $publisher->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => []], ['type' => 'text', 'text' => 'Done reading']]]);
    $publisher->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => 'file']);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")->wait(1)
        ->assertSeeIn('[data-testid="thread-title-block"]', 'Fix the login redirect')
        ->assertVisible('[data-testid="thread-tool-activity"] [data-testid="tool-activity-chevron"]')
        ->assertSeeIn('[data-testid="thread-model-chip"]', 'claude-sonnet-5-5')
        ->assertNoJavaScriptErrors();
});

test('the phone Chat tab shows an activity dot only while Claude is busy', function () {
    $this->actingAs(User::factory()->create());
    $busy = Thread::factory()->create(['runner_state' => RunnerState::Busy]);
    $idle = Thread::factory()->create(['runner_state' => RunnerState::Idle]);

    visit(route('threads.show', $busy))->on()->mobile()
        ->assertPresent('[data-testid="thread-phone-tab-chat-dot"]')
        ->assertNoJavaScriptErrors();

    visit(route('threads.show', $idle))->on()->mobile()
        ->assertPresent('[data-testid="thread-phone-tabs"]')
        ->assertMissing('[data-testid="thread-phone-tab-chat-dot"]');
});
