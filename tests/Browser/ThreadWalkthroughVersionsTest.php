<?php

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadWalkthrough;
use App\Models\User;
use App\Models\YakTask;

function walkthroughReply(Thread $thread, int $sequence, string $text): void
{
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => "m{$sequence}", 'blocks' => [['type' => 'text', 'text' => $text, 'html' => "<p>{$text}</p>"]]]]);
}

it('shows each walkthrough version where it was recorded', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'branch' => 'yak/junk']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'branch_name' => 'yak/junk', 'pr_url' => 'https://github.com/acme/app/pull/9']);

    walkthroughReply($thread, 1, 'Delete is in the row menu.');
    walkthroughReply($thread, 2, 'Bulk delete now asks first.');

    $earlier = ThreadWalkthrough::factory()->archived()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'number' => 1, 'anchor_sequence' => 1, 'duration_seconds' => 41]);
    Artifact::create(['yak_task_id' => $task->id, 'thread_walkthrough_id' => $earlier->id, 'type' => 'file', 'role' => 'archived_cut', 'filename' => 'walkthrough-1-walkthrough.mp4', 'disk_path' => "{$task->id}/walkthroughs/1/walkthrough.mp4", 'size_bytes' => 1]);
    ThreadWalkthrough::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'number' => 2, 'anchor_sequence' => 2]);
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'video_cut', 'role' => 'cut', 'filename' => 'walkthrough.mp4', 'disk_path' => "{$task->id}/walkthrough.mp4", 'size_bytes' => 1]);

    $this->actingAs($user);

    $page = visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="earlier-walkthrough"][data-number="1"]')
        ->assertVisible('[data-testid="thread-walkthrough"][data-number="2"]')
        ->assertVisible('[data-testid="walkthrough-latest"]')
        ->assertMissing('[data-testid="earlier-walkthrough-media"]');

    $page->assertScript("(() => {
        const earlier = document.querySelector('[data-testid=\"earlier-walkthrough\"]');
        const latest = document.querySelector('[data-testid=\"thread-walkthrough\"]');
        const reply = [...document.querySelectorAll('[data-testid=\"thread-conversation-scroller\"] p')].find((element) => element.textContent === 'Bulk delete now asks first.');

        return Boolean(earlier.compareDocumentPosition(reply) & Node.DOCUMENT_POSITION_FOLLOWING) && Boolean(reply.compareDocumentPosition(latest) & Node.DOCUMENT_POSITION_FOLLOWING);
    })()", true);

    $page->click('[data-testid="earlier-walkthrough-toggle"]')
        ->assertVisible('[data-testid="earlier-walkthrough-media"] video')
        ->assertNoJavaScriptErrors();
});
