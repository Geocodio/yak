<?php

use App\Http\Resources\ThreadRunLogData;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function logRun(Thread $thread, int $startSequence): TaskRun
{
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $startSequence, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);

    return $run;
}

function logEntry(Thread $thread, int $sequence, array $payload): ThreadEvent
{
    return ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => $payload]);
}

it('lists every step of a run in order and pairs a tool call with its output', function () {
    $thread = Thread::factory()->create();
    $first = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'user', 'text' => 'Fix the bug', 'kind' => 'prompt']);
    logEntry($thread, 3, ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'ls -la']]]]);
    logEntry($thread, 4, ['role' => 'tool_result', 'toolUseId' => 'toolu_1', 'isError' => false, 'text' => 'total 8']);
    logEntry($thread, 5, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Done **now**']]]);
    $second = logRun($thread, 6);
    logEntry($thread, 7, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Second run']]]);

    $response = $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$first->id}/log")->assertOk();

    expect($response->json('nextCursor'))->toBeNull()
        ->and(array_column($response->json('entries'), 'id'))->toBe([2, 3, 5])
        ->and($response->json('entries.0.kind'))->toBe('prompt')
        ->and($response->json('entries.1.kind'))->toBe('tool')
        ->and($response->json('entries.1.input'))->toBe('ls -la')
        ->and($response->json('entries.1.output'))->toBe('total 8')
        ->and($response->json('entries.2.html'))->toContain('<strong>now</strong>');

    $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$second->id}/log")
        ->assertOk()->assertJsonCount(1, 'entries');
});

it('pages 50 entries at a time by cursor', function () {
    $thread = Thread::factory()->create();
    $run = logRun($thread, 1);

    foreach (range(2, 61) as $sequence) {
        logEntry($thread, $sequence, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => "Step {$sequence}"]]]);
    }

    $user = User::factory()->create();
    $page = $this->actingAs($user)->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log")->assertOk();

    expect($page->json('entries'))->toHaveCount(50)->and($page->json('nextCursor'))->toBe(51);

    $rest = $this->actingAs($user)->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log?cursor=51")->assertOk();

    expect($rest->json('entries'))->toHaveCount(10)->and($rest->json('nextCursor'))->toBeNull()->and($rest->json('entries.0.id'))->toBe(52);
});

it('searches the steps of a run, including tool output and wildcard characters', function () {
    $thread = Thread::factory()->create();
    $run = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Reading the config']]]);
    logEntry($thread, 3, ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Read', 'input' => ['file_path' => '/app/config/100%_done.php']]]]);
    logEntry($thread, 4, ['role' => 'tool_result', 'toolUseId' => 'toolu_1', 'isError' => false, 'text' => 'needle in output']);
    $user = User::factory()->create();

    $ids = fn (string $search): array => array_column($this->actingAs($user)->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log?search=" . urlencode($search))->json('entries'), 'id');

    expect($ids('config'))->toBe([2, 3])
        ->and($ids('needle'))->toBe([4])
        ->and($ids('100%_done'))->toBe([3])
        ->and($ids('/app/config'))->toBe([3])
        ->and($ids('no such text'))->toBe([]);
});

it('answers an empty list for a run without events', function () {
    $thread = Thread::factory()->create();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => YakTask::factory()->create(['thread_id' => $thread->id])->id]);

    $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log")
        ->assertOk()->assertExactJson(['entries' => [], 'nextCursor' => null]);
});

it('refuses a run of another thread and a thread the viewer cannot see', function () {
    $thread = Thread::factory()->create();
    $foreign = logRun(Thread::factory()->create(), 1);
    $own = logRun($thread, 1);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson("/threads/{$thread->public_id}/runs/{$foreign->id}/log")->assertNotFound();

    $hidden = Thread::factory()->private()->create();
    $hiddenRun = logRun($hidden, 1);
    $this->actingAs($user)->getJson("/threads/{$hidden->public_id}/runs/{$hiddenRun->id}/log")->assertNotFound();
});

it('reads the log of a task of a legacy thread and refuses a task of another one', function () {
    $task = YakTask::factory()->success()->create();
    $thread = Thread::factory()->create(['is_legacy' => true, 'legacy_root_task_id' => $task->id]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'Starting Claude agent', 'created_at' => now()]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'Pushed branch', 'created_at' => now()]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson("/threads/{$thread->public_id}/tasks/{$task->public_id}/log")->assertOk();
    expect(array_column($response->json('entries'), 'text'))->toBe(['Starting Claude agent', 'Pushed branch']);

    $search = $this->actingAs($user)->getJson("/threads/{$thread->public_id}/tasks/{$task->public_id}/log?search=Pushed")->assertOk();
    expect($search->json('entries'))->toHaveCount(1);

    $other = YakTask::factory()->success()->create();
    $this->actingAs($user)->getJson("/threads/{$thread->public_id}/tasks/{$other->public_id}/log")->assertNotFound();
});

it('opens the page with the log at an entry of this thread and ignores any other entry', function () {
    $thread = Thread::factory()->create();
    $run = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Hello']]]);
    $foreign = Thread::factory()->create();
    logRun($foreign, 1);
    logEntry($foreign, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Foreign']]]);
    $user = User::factory()->create();

    $this->actingAs($user)->get("/threads/{$thread->public_id}?log=2")->assertInertia(fn (Assert $page) => $page
        ->where('openLog', ['runId' => (string) $run->id, 'entryId' => 2]));
    $this->actingAs($user)->get("/threads/{$thread->public_id}?log=99")->assertInertia(fn (Assert $page) => $page->where('openLog', null));
    $this->actingAs($user)->get("/threads/{$thread->public_id}?log=abc")->assertInertia(fn (Assert $page) => $page->where('openLog', null));
});

it('gives the page the runs that have events with where each answer ends', function () {
    $thread = Thread::factory()->create();
    $first = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'One']]]);
    $second = logRun($thread, 3);
    logEntry($thread, 4, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Two']]]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $first->yak_task_id]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('runs.0.runId', (string) $first->id)->where('runs.0.afterSequence', 2)->where('runs.0.model', $first->model)
        ->where('runs.1.runId', (string) $second->id)->where('runs.1.afterSequence', 4)
        ->missing('runs.2'));
});

it('keeps a search to the entries of the run it was asked for', function () {
    $thread = Thread::factory()->create();
    $first = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'needle in the first run']]]);
    logRun($thread, 3);
    logEntry($thread, 4, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'needle in the second run']]]);
    $other = Thread::factory()->create();
    logRun($other, 1);
    logEntry($other, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'needle elsewhere']]]);

    $ids = array_column($this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$first->id}/log?search=needle")->json('entries'), 'id');

    expect($ids)->toBe([2]);
});

it('serves the last and a still running run to its newest event', function () {
    $thread = Thread::factory()->create();
    $runs = [logRun($thread, 1), logRun($thread, 3), logRun($thread, 6)];
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'a']]]);
    logEntry($thread, 4, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'b']]]);
    logEntry($thread, 5, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'c']]]);
    logEntry($thread, 7, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'd']]]);
    logEntry($thread, 8, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'e']]]);
    $user = User::factory()->create();

    $ids = fn (TaskRun $run): array => array_column($this->actingAs($user)->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log")->json('entries'), 'id');

    expect($ids($runs[0]))->toBe([2])->and($ids($runs[1]))->toBe([4, 5])->and($ids($runs[2]))->toBe([7, 8]);
});

it('cuts the long fields of an entry at 16 KB and says so', function () {
    $thread = Thread::factory()->create();
    $run = logRun($thread, 1);
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'cat big']]]]);
    logEntry($thread, 3, ['role' => 'tool_result', 'toolUseId' => 'toolu_1', 'isError' => false, 'text' => str_repeat('x', 40000)]);

    $entry = $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log")->json('entries.0');

    expect(strlen($entry['output']))->toBe(16384)->and($entry['isTruncated'])->toBeTrue();
});

it('still serves the log of a task of a legacy thread whose tasks moved to a continuation thread', function () {
    $task = YakTask::factory()->success()->create();
    $thread = Thread::factory()->create(['is_legacy' => true, 'legacy_root_task_id' => $task->id]);
    $continuation = Thread::factory()->create();
    $task->forceFill(['thread_id' => $continuation->id])->save();
    $log = TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'Pushed branch', 'created_at' => now()]);

    $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/tasks/{$task->public_id}/log")->assertOk()->assertJsonCount(1, 'entries');
    expect(ThreadRunLogData::locate($thread, $log->id))->toBe(['runId' => $task->public_id, 'entryId' => $log->id])
        ->and(ThreadRunLogData::locate($thread, $log->id + 999))->toBeNull();
});

it('marks a run active only while its turn has not ended', function () {
    $thread = Thread::factory()->create();
    $states = ['running' => true, 'pending' => true, 'waiting_for_account' => true, 'completed' => false, 'stopped_by_guard' => false, 'failed' => false, 'interrupted' => false];
    $expected = [];

    foreach ($states as $state => $isActive) {
        $run = logRun($thread, count($expected) * 2 + 1);
        $run->forceFill(['turn_state' => $state])->save();
        logEntry($thread, count($expected) * 2 + 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => $state]]]);
        $expected[(string) $run->id] = $isActive;
    }

    $runs = collect(ThreadRunLogData::runs($thread))->pluck('isActive', 'runId')->all();

    expect($runs)->toBe($expected);
});

it('shows a viewer without drive rights the active run as active', function () {
    $thread = Thread::factory()->private()->create();
    $run = logRun($thread, 1);
    $run->forceFill(['turn_state' => 'running'])->save();
    logEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'live']]]);
    $reader = User::factory()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $reader->id]);

    $this->actingAs($reader)->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('canDrive', false)->where('runs.0.isActive', true));
});
