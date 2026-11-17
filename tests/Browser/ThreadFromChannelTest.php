<?php

use App\Enums\ThreadSource;
use App\Enums\TurnMessageState;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
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
        ->assertSeeIn('[data-testid="overview-request"]', 'Mathias Hansen')
        ->assertSeeIn('[data-testid="overview-run-details"]', '1 run')
        ->click('[data-testid="run-log-button"]')
        ->assertVisible('[data-testid="transcript-overlay"]')
        ->assertSee('git rm app/Importer.php')
        ->assertNoJavaScriptErrors();
});

it('opens the full log at an entry named in the address and ignores an entry of another thread', function () {
    $this->actingAs(User::factory()->create());
    $thread = channelThread();

    visit("/threads/{$thread->public_id}?log=4")
        ->assertVisible('[data-testid="transcript-overlay"]')
        ->assertSeeIn('[data-testid="log-entry-open"]', 'I removed the importer.')
        ->assertNoJavaScriptErrors();

    visit("/threads/{$thread->public_id}?log=999")
        ->assertMissing('[data-testid="transcript-overlay"]')
        ->assertVisible('[data-testid="thread-header"]')
        ->assertNoJavaScriptErrors();
});
