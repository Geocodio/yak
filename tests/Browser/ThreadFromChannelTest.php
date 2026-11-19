<?php

use App\Enums\ThreadSource;
use App\Enums\ThreadVisibility;
use App\Enums\TurnMessageState;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadShare;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;

function channelThread(): Thread
{
    $thread = Thread::factory()->create([
        'title' => 'Delete the source importer',
        'source' => ThreadSource::Linear,
        'source_reference' => 'ENG-1576',
        'starter_name' => 'Mathias Hansen',
        'source_url' => 'https://linear.app/geocodio/issue/ENG-1576',
        'branch' => 'yak/LINEAR-ENG-1576',
    ]);
    $task = YakTask::factory()->success()->create([
        'thread_id' => $thread->id,
        'repo' => $thread->repo,
        'source' => 'linear',
        'external_id' => 'LINEAR-ENG-1576',
        'author_name' => 'Mathias Hansen',
        'description' => 'Delete the importer and the code only it used.',
        'branch_name' => 'yak/LINEAR-ENG-1576',
        'pr_url' => 'https://github.com/acme/widgets/pull/3105',
        'pr_number' => 3105,
        'pr_opened_at' => now(),
    ]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'commits' => 2, 'started_at' => now()->subHour()]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => $task->description]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'CI result received', 'metadata' => ['passed' => true], 'created_at' => now()->subMinutes(30)]);

    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 2, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'git rm app/Importer.php']]]]]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 3, 'type' => 'entry', 'payload' => ['role' => 'tool_result', 'toolUseId' => 'toolu_1', 'isError' => false, 'text' => 'rm app/Importer.php']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 4, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'I removed the importer.', 'html' => '<p>I removed the importer.</p>']]]]);
    $thread->forceFill(['event_sequence' => 4])->save();

    return $thread;
}

it('shows where a channel thread came from, what its run reached and its full log', function () {
    $this->actingAs(User::factory()->create());
    $thread = channelThread();

    visit("/threads/{$thread->public_id}")
        ->assertSeeIn('[data-testid="thread-source-link"]', 'Linear ENG-1576')
        ->assertAttribute('[data-testid="thread-source-link"]', 'href', 'https://linear.app/geocodio/issue/ENG-1576')
        ->assertSeeIn('[data-testid="thread-message-via"]', 'via')
        ->assertSeeIn('[data-testid="thread-message-via"]', 'Linear ENG-1576')
        ->assertVisible('[data-testid="milestone-row"]')
        ->assertSeeIn('[data-testid="milestone-row"]', 'Pushed yak/LINEAR-ENG-1576')
        ->assertSeeIn('[data-testid="milestone-row"]', 'CI passed')
        ->assertSeeIn('[data-testid="milestone-row"]', 'Pull request #3105')
        ->assertSeeIn('[data-testid="milestone-review-pull-request"]', 'Review PR #3105')
        ->assertSeeIn('[data-testid="thread-composer-hint"]', 'Replies here and in the Linear issue land in this thread.')
        ->assertAttribute('[data-testid="request-source"] a', 'href', 'https://linear.app/geocodio/issue/ENG-1576')
        ->assertSeeIn('[data-testid="overview-request"]', 'Mathias Hansen')
        ->assertSeeIn('[data-testid="overview-run-details"]', '1 run')
        ->click('[data-testid="run-log-button"]')
        ->assertVisible('[data-testid="transcript-overlay"]')
        ->assertSee('git rm app/Importer.php')
        ->assertMissing('[data-testid="run-picker"]')
        ->assertMissing('[data-testid="attempt-1"]')
        ->assertNoJavaScriptErrors();
});

it('shows the empty log text and an error with Retry in the full log', function () {
    $this->actingAs(User::factory()->create());
    $thread = channelThread();
    $answer = fn (string $body, int $status) => "(() => { const original = window.fetch; window.fetch = (url, options) => String(url).includes('/log') ? Promise.resolve(new Response('{$body}', { status: {$status}, headers: { 'Content-Type': 'application/json' } })) : original(url, options); })();";

    $page = visit("/threads/{$thread->public_id}");
    $page->script($answer('{"entries":[],"nextCursor":null}', 200));
    $page->click('[data-testid="run-log-button"]')
        ->assertSeeIn('[data-testid="transcript-empty"]', 'This run has no recorded steps.')
        ->assertNoJavaScriptErrors();

    $failing = visit("/threads/{$thread->public_id}");
    $failing->script($answer('{}', 404));
    $failing->click('[data-testid="run-log-button"]')
        ->assertVisible('[data-testid="transcript-error"]')
        ->assertSeeIn('[data-testid="transcript-error"]', 'The log could not be loaded.')
        ->assertPresent('[data-testid="transcript-retry"]');
});

it('opens the full log at an entry named in the address and ignores an entry of another thread', function () {
    $this->actingAs(User::factory()->create());
    $thread = channelThread();

    visit("/threads/{$thread->public_id}?log=4")
        ->assertVisible('[data-testid="transcript-overlay"]')
        ->assertSeeIn('[data-testid="log-entry-open"]', 'I removed the importer.')
        ->assertNoJavaScriptErrors();

    $other = Thread::factory()->create();
    ThreadEvent::factory()->create(['thread_id' => $other->id, 'sequence' => 50, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Elsewhere']]]]);

    visit("/threads/{$thread->public_id}?log=50")
        ->assertMissing('[data-testid="transcript-overlay"]')
        ->assertVisible('[data-testid="thread-header"]');

    visit("/threads/{$thread->public_id}?log=999")
        ->assertMissing('[data-testid="transcript-overlay"]')
        ->assertVisible('[data-testid="thread-header"]')
        ->assertNoJavaScriptErrors();
});

it('shows a thread Yak started from a Sentry alert that stopped after CI failed', function () {
    $this->actingAs(User::factory()->create());
    $thread = Thread::factory()->create([
        'title' => 'TypeError in BatchGeocodeJob::handle',
        'source' => ThreadSource::Sentry,
        'branch' => 'yak/SENTRY-GEOCODIO-4KX',
    ]);
    $task = YakTask::factory()->failed()->create([
        'thread_id' => $thread->id,
        'repo' => $thread->repo,
        'source' => 'sentry',
        'description' => 'TypeError: null given for $spreadsheet.',
        'branch_name' => 'yak/SENTRY-GEOCODIO-4KX',
        'attempts' => 2,
        'author_name' => null,
        'started_by_user_id' => null,
    ]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'commits' => 1, 'started_at' => now()->subHour()]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => TurnMessageState::Completed, 'text' => $task->description]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'CI result received', 'metadata' => ['passed' => false], 'created_at' => now()->subMinutes(5)]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 2, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'I added a guard.', 'html' => '<p>I added a guard.</p>']]]]);
    $thread->forceFill(['event_sequence' => 2])->save();

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="thread-progress-row"]')
        ->assertPresent('[data-testid="thread-progress-row"] [data-state="failed"]')
        ->assertSeeIn('[data-testid="thread-progress-row"] [data-state="failed"]', 'CI failed')
        ->assertSeeIn('[data-testid="thread-message-via"]', 'from')
        ->assertSeeIn('[data-testid="thread-composer-hint"]', 'Your first message makes you the owner.')
        ->assertSeeIn('[data-testid="request-status"]', 'Stopped')
        ->assertSeeIn('[data-testid="request-responsible"]', 'Nobody yet')
        ->assertSeeIn('[data-testid="environment-ci"]', 'Failed, attempt 2 of')
        ->assertNoJavaScriptErrors();
});

it('shows a viewer who cannot drive no footer for a run that is still active', function () {
    $reader = User::factory()->create();
    $thread = channelThread();
    $thread->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id])->save();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $reader->id]);
    TaskRun::query()->where('thread_id', $thread->id)->update(['turn_state' => 'running']);
    $this->actingAs($reader);

    visit("/threads/{$thread->public_id}")
        ->assertVisible('[data-testid="thread-header"]')
        ->assertMissing('[data-testid="run-footer"]')
        ->assertMissing('[data-testid="run-log-button"]')
        ->assertNoJavaScriptErrors();

    TaskRun::query()->where('thread_id', $thread->id)->update(['turn_state' => 'completed']);

    visit("/threads/{$thread->public_id}")->assertVisible('[data-testid="run-footer"]');
});
