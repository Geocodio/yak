<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Http\Resources\StackData;
use App\Http\Resources\ThreadAgentsData;
use App\Jobs\StartChildThreadJob;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Stacks\StackListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function agentsPanelStates(Thread $parent): array
{
    return collect(ThreadAgentsData::for($parent)['subThreads'])->pluck('state', 'id')->all();
}

function stackPanelStates(Thread $parent): array
{
    return collect(StackData::for($parent, User::factory()->create())['children'])->pluck('state', 'id')->all();
}

beforeEach(function () {
    $this->parent = Thread::factory()->create();
});

it('maps one fact set to one state for every surface', function (array $facts, string $expected) {
    expect(StackListing::resolveState(...$facts))->toBe($expected);
})->with([
    'closed' => [[false, false, false, false, false, true, true], 'closed'],
    'failed' => [[true, true, false, false, false, true, false], 'failed'],
    'done' => [[true, false, true, false, false, true, false], 'done'],
    'waiting for a spot' => [[true, false, false, true, false, false, false], 'queued'],
    'research never waits for a spot' => [[true, false, false, true, true, false, false], 'starting'],
    'no runs and no queue is starting' => [[true, false, false, false, false, false, false], 'starting'],
    'running turn' => [[true, false, false, false, false, true, true], 'working'],
    'idle between turns' => [[true, false, false, false, false, true, false], 'waiting'],
]);

it('shows a child with no runs and no queue as starting, not waiting for a spot', function () {
    $starting = Thread::factory()->child($this->parent)->create();
    $queued = Thread::factory()->child($this->parent)->create(['queued_for_slot_at' => now()]);
    $research = Thread::factory()->child($this->parent)->create(['kind' => ThreadKind::Research, 'queued_for_slot_at' => now()]);

    foreach ([agentsPanelStates($this->parent), stackPanelStates($this->parent)] as $states) {
        expect($states[$starting->public_id])->toBe('starting')
            ->and($states[$queued->public_id])->toBe('queued')
            ->and($states[$research->public_id])->toBe('starting');
    }

    app(ChildCardPublisher::class)->publish($starting);
    $payload = json_decode(DB::table('thread_events')->where('thread_id', $this->parent->id)->where('type', 'child')->value('payload'), true);

    expect($payload['state'])->toBe('starting');
});

it('shows a child that ran a turn as working or waiting for the person', function () {
    $child = Thread::factory()->child($this->parent)->create();
    $task = YakTask::factory()->create(['thread_id' => $child->id]);
    $run = TaskRun::factory()->create(['thread_id' => $child->id, 'yak_task_id' => $task->id, 'turn_state' => TurnState::Running]);

    expect(agentsPanelStates($this->parent)[$child->public_id])->toBe('working');

    $run->forceFill(['turn_state' => TurnState::Completed])->save();

    expect(agentsPanelStates($this->parent)[$child->public_id])->toBe('waiting');
});

it('publishes a delivered result as a rendered child.result event', function () {
    $child = Thread::factory()->child($this->parent)->create(['title' => 'Read the README']);
    $row = StackThreadMessage::factory()->create([
        'root_thread_id' => $this->parent->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $this->parent->id,
        'kind' => StackThreadMessageKind::Result,
        'text' => "1. The first lines of `README.md`:\n\n- one",
    ]);

    app(ChildCardPublisher::class)->publishResult($row);

    $event = json_decode(DB::table('thread_events')->where('thread_id', $this->parent->id)->where('type', 'child.result')->value('payload'), true);

    expect($event)->toMatchArray(['id' => $row->id, 'childThreadId' => $child->public_id, 'title' => 'Read the README', 'kind' => 'implement', 'state' => 'done', 'reason' => null])
        ->and($event['html'])->toContain('<code>README.md</code>')->toContain('<li>one</li>');
});

it('publishes a failure with its reason', function () {
    $child = Thread::factory()->child($this->parent)->create();
    $row = StackThreadMessage::factory()->create([
        'root_thread_id' => $this->parent->id,
        'from_thread_id' => $child->id,
        'to_thread_id' => $this->parent->id,
        'kind' => StackThreadMessageKind::Failure,
        'text' => "Sub-thread builder failed before finishing.\nSecond line.",
    ]);

    app(ChildCardPublisher::class)->publishResult($row);

    $event = json_decode(DB::table('thread_events')->where('thread_id', $this->parent->id)->where('type', 'child.result')->value('payload'), true);

    expect($event['state'])->toBe('failed')->and($event['reason'])->toBe('Sub-thread builder failed before finishing.');
});

it('starts the waiting sub-thread when the person raises the limit by one', function () {
    Queue::fake();
    config(['yak.threads.person_awake_cap' => 4]);
    $root = Thread::factory()->create(['child_awake_limit' => 1]);
    Thread::factory()->child($root)->create(['sandbox_state' => ThreadSandboxState::Running]);
    $waiting = Thread::factory()->child($root)->create(['queued_for_slot_at' => now()]);

    $this->actingAs(User::factory()->create())->patch(route('threads.stack.limit', $root), ['limit' => $root->child_awake_limit + 1])->assertRedirect();

    expect($root->fresh()->child_awake_limit)->toBe(2);
    Queue::assertPushed(StartChildThreadJob::class, fn (StartChildThreadJob $job): bool => $job->childThreadId === $waiting->id);
});
