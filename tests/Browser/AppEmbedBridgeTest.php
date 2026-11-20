<?php

use App\Enums\StopKind;
use App\Enums\TurnMessageState;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Stands in for the app's script message handler; every message the page posts lands in `window.__sent`. */
function stubAppHandler(mixed $page): void
{
    $page->script('window.__sent = []; window.webkit = { messageHandlers: { yak: { postMessage: (message) => window.__sent.push(message) } } }; true');
}

/** The messages of this type the stubbed handler got, as JSON. */
function sentOfType(mixed $page, string $type, string $result = 'JSON.stringify(sent)'): mixed
{
    return settled($page, "window.__sent.some((message) => message.type === '{$type}')", "(() => { const sent = window.__sent.filter((message) => message.type === '{$type}'); return {$result}; })()");
}

it('tells the app the thread is ready once it has loaded', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Add a retry to the import job']);

    $page = visitEmbedThread($thread, $headers);
    $ready = settled($page, "(window.__yakEmbedLog ?? []).some((message) => message.type === 'ready')", "(window.__yakEmbedLog ?? []).find((message) => message.type === 'ready')");

    expect($ready)->toMatchArray(['v' => 1, 'type' => 'ready', 'threadId' => $thread->public_id, 'title' => 'Add a retry to the import job'])
        ->and($ready['url'])->toBe("/app/embed/threads/{$thread->public_id}");
    $page->assertNoJavaScriptErrors();
});

it('sends a link for a visit outside the embed pages and does not navigate', function () {
    [$user, , $headers] = embedPhone();
    $parent = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Build the importer']);
    $child = Thread::factory()->child($parent)->create(['title' => 'Build the client', 'created_by_user_id' => $user->id]);

    $page = visitEmbedThread($child, $headers);
    stubAppHandler($page);
    $page->click('[data-testid="child-parent-banner"] a');

    $sent = sentOfType($page, 'link', 'sent.map((message) => message.url)');

    expect($sent)->toHaveCount(1)->and($sent[0])->toEndWith("/threads/{$parent->public_id}");
    $page->assertScript('location.pathname', "/app/embed/threads/{$child->public_id}");
});

it('asks the app for the Files pane from a changed-file chip', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Edit', 'input' => ['file_path' => 'app/Jobs/ImportJob.php', 'old_string' => 'a', 'new_string' => 'b']],
        ['type' => 'text', 'text' => 'The retry is in.'],
    ]]);
    app(ThreadEventPublisher::class)->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => 'ok']);

    $page = visitEmbedThread($thread, $headers);
    stubAppHandler($page);
    $page->click('[data-testid="thread-edits"] > summary')->click('[data-testid="thread-edits-open-changes"]');

    expect(sentOfType($page, 'openPane'))->toBe('[{"v":1,"type":"openPane","pane":"files","mode":"changed","path":"app/Jobs/ImportJob.php"}]');
});

it('sends threadChanged when a move lands on the new thread', function () {
    Queue::fake();
    [$user, , $headers] = embedPhone();
    $repository = Repository::factory()->create(['slug' => 'o-r', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'other-repo', 'is_active' => true]);
    $task = YakTask::factory()->failed()->create(['repo' => $repository->slug, 'pr_url' => null, 'source' => 'dashboard', 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'CI failed twice on the same test.']);
    $thread = Thread::query()->findOrFail($task->thread_id);
    $thread->update(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);

    $page = visitEmbedThread($thread, $headers);
    stubAppHandler($page);
    $page->click('[data-testid="stop-move"]')->click('[data-testid="move-target"]')->click('[data-testid="move-confirm"]');

    $sent = sentOfType($page, 'threadChanged', 'sent[0]');
    $next = Thread::query()->where('continued_from_thread_id', $thread->id)->firstOrFail();

    expect($sent)->toMatchArray(['v' => 1, 'type' => 'threadChanged', 'threadId' => $next->public_id, 'previousThreadId' => $thread->public_id]);
    $page->assertScript('location.pathname', "/app/embed/threads/{$next->public_id}");
});

it('switches thread on open without a full page load and follows focus', function () {
    [$user, , $headers] = embedPhone();
    $first = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'First thread']);
    $second = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'title' => 'Second thread']);

    $page = visitEmbedThread($first, $headers);
    stubAppHandler($page);
    $page->script("window.__sameDocument = true; window.__yakEmbed.receive({ v: 1, type: 'open', threadId: '{$second->public_id}', fragment: 'card-question' }); true");

    expect(settled($page, "location.pathname.endsWith('{$second->public_id}')", 'window.__sameDocument === true && location.hash'))->toBe('#card-question');
    expect(sentOfType($page, 'threadChanged', 'sent[0].previousThreadId'))->toBe($first->public_id);
});

it('shows the app outbox as unsent rows and ignores unknown messages', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);

    $page = visitEmbedThread($thread, $headers);
    $item = "{ key: 'k1', text: 'Also cover the export job', status: 'waiting', reason: null, createdAt: '2026-11-20T10:15:00Z', attachmentCount: 0 }";
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'somethingNew' }); window.__yakEmbed.receive({ v: 1, type: 'outbox', threadId: '{$thread->public_id}', items: [{$item}] }); true");

    $page->assertSeeIn('[data-testid="thread-message-unsent"]', 'Also cover the export job')
        ->assertPresent('[data-testid="thread-message-unsent-discard"]');
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'outbox', threadId: '{$thread->public_id}', items: [] }); true");
    $page->assertMissing('[data-testid="thread-message-unsent"]')->assertNoJavaScriptErrors();
});

it('tells the app once when the session ends and a reload meets the 401', function () {
    [$user, $device, $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);

    $page = visitEmbedThread($thread, $headers);
    stubAppHandler($page);
    $device->delete();
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'refresh' }); true");

    expect(sentOfType($page, 'auth', 'sent.length'))->toBe(1);

    $page->script("window.__yakEmbed.receive({ v: 1, type: 'refresh' }); true");
    $page->script('new Promise((resolve) => setTimeout(resolve, 1500))');

    expect($page->script("window.__sent.filter((message) => message.type === 'auth').length"))->toBe(1);
});

it('does not show an outbox item twice when the server already queued it', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $root = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Start']);
    PendingSteeringMessage::query()->create(['root_task_id' => $root->id, 'text' => 'Queued on the server', 'source' => 'thread', 'mode' => 'queue', 'uuid' => (string) Str::uuid(), 'send_key' => 'key-queued']);

    $page = visitEmbedThread($thread, $headers);
    $item = "{ key: 'key-queued', text: 'Queued on the server', status: 'waiting', reason: null, createdAt: '2026-11-20T10:15:00Z', attachmentCount: 0 }";
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'outbox', threadId: '{$thread->public_id}', items: [{$item}] }); true");
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    $page->assertMissing('[data-testid="thread-message-unsent"]')->assertNoJavaScriptErrors();
});

it('hands a message attachment download to the app, from the tile and from the chip', function () {
    Storage::fake('artifacts');
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Read [File #1] first']);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed]);
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => $task->id, 'original_name' => 'archive.zip', 'mime_type' => 'application/zip', 'reference' => 'File #1']);
    Storage::disk('artifacts')->put($attachment->disk_path, 'notes');

    $page = visitEmbedThread($thread, $headers);
    stubAppHandler($page);
    settled($page, "document.querySelector('[data-testid=\"sent-attachments\"] a') !== null", 'document.querySelectorAll("[data-attachment-ref]").length');
    $page->script("document.querySelector('[data-testid=\"sent-attachments\"] a').click(); document.querySelector('[data-attachment-ref]').click(); true");

    $sent = sentOfType($page, 'quickLook', 'sent.map((message) => message.name)');

    expect($sent)->toBe(['archive.zip', 'archive.zip']);
    $page->assertScript('location.pathname', "/app/embed/threads/{$thread->public_id}");
});

it('tells the app when a card was answered, even with a refresh during the post', function () {
    [$thread, $headers] = embedQuestionThread();

    $page = visitEmbedThread($thread, $headers)->wait(2);
    stubAppHandler($page);
    $page->click('[data-testid="question-scope"] >> text=Small')->click('[data-testid="questions-submit"]');
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'refresh' }); true");

    expect(sentOfType($page, 'cardAnswered', 'sent.map(({ card, action, ok }) => ({ card, action, ok }))'))->toBe([['card' => 'question', 'action' => 'answer', 'ok' => true]]);
});

it('names the attachments of an unsent message without text and offers discard only where it is safe', function () {
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);

    $page = visitEmbedThread($thread, $headers);
    $item = "{ key: 'k2', text: '', status: 'sending', reason: null, createdAt: '2026-11-20T10:15:00Z', attachmentCount: 2 }";
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'outbox', threadId: '{$thread->public_id}', items: [{$item}] }); true");

    $page->assertSeeIn('[data-testid="thread-message-unsent"]', '2 attachments')
        ->assertMissing('[data-testid="thread-message-unsent-discard"]')
        ->assertNoJavaScriptErrors();
});
