<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\TurnState;
use App\Http\Resources\StackData;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContractFixture;

function startTurnFor(Thread $thread, TurnState $state): void
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'turn_state' => $state]);
}

it('gives each sub-thread the state the stack answer gives it, and no root row has one', function () {
    $user = User::factory()->create(['name' => 'Test person']);
    $root = Thread::factory()->create(['title' => 'Move search', 'created_by_user_id' => $user->id]);
    $make = fn (string $title, array $attributes = []) => Thread::factory()->child($root)->create($attributes + ['title' => $title, 'created_by_user_id' => $user->id]);

    $starting = $make('starting');
    $queued = $make('queued', ['queued_for_slot_at' => now()]);
    $done = $make('done', ['result_summary' => 'All done']);
    $failed = $make('failed');
    StackThreadMessage::factory()->create(['root_thread_id' => $root->id, 'from_thread_id' => $failed->id, 'to_thread_id' => $root->id, 'kind' => StackThreadMessageKind::Failure]);
    $takenOver = $make('takenOver', ['taken_over_at' => now()]);
    $working = $make('working');
    startTurnFor($working, TurnState::Running);
    $waiting = $make('waiting');
    startTurnFor($waiting, TurnState::Completed);

    $response = $this->getJson('/api/app/v1/threads?filter=everyone', phoneHeadersFor($user))->assertOk();
    $row = collect($response->json('threads'))->firstWhere('id', $root->public_id);
    $children = collect($row['children'])->keyBy('id');
    $stack = collect(StackData::for($root, $user)['children'])->keyBy('id');

    expect($row)->not->toHaveKey('stackState')
        ->and($children[$starting->public_id]['stackState'])->toBe('starting')
        ->and($children[$queued->public_id]['stackState'])->toBe('queued')
        ->and($children[$done->public_id]['stackState'])->toBe('done')
        ->and($children[$failed->public_id]['stackState'])->toBe('failed')
        ->and($children[$working->public_id]['stackState'])->toBe('working')
        ->and($children[$waiting->public_id]['stackState'])->toBe('waiting')
        ->and($children[$takenOver->public_id]['takenOver'])->toBeTrue()
        ->and($children[$starting->public_id]['takenOver'])->toBeFalse();

    foreach ($children as $id => $child) {
        expect($child['stackState'])->toBe($stack[$id]['state'])->and($child['takenOver'])->toBe($stack[$id]['takenOver']);
    }

    $closedRoot = Thread::factory()->closed()->create(['created_by_user_id' => $user->id]);
    $closed = Thread::factory()->child($closedRoot)->closed()->create(['created_by_user_id' => $user->id]);
    $closedChildren = collect($this->getJson('/api/app/v1/threads?filter=closed', phoneHeadersFor($user))->json('threads'))->flatMap(fn (array $row) => $row['children'])->keyBy('id');
    expect($closedChildren[$closed->public_id]['stackState'])->toBe('closed');

    ContractFixture::assertMatches('app', 'threads-children', collect($response->json())->only(['threads'])->all(), [
        'threads.*.id', 'threads.*.children.*.id', 'threads.*.starter.key', 'threads.*.children.*.starter.key', 'threads.*.lastActivityAt', 'threads.*.children.*.lastActivityAt',
    ]);
});

it('keeps the children of a hidden root out of the list', function () {
    $viewer = User::factory()->create();
    $owner = User::factory()->create();
    $visibleRoot = Thread::factory()->create(['created_by_user_id' => $viewer->id]);
    $shownChild = Thread::factory()->child($visibleRoot)->create(['created_by_user_id' => $viewer->id]);
    $hiddenRoot = Thread::factory()->private($owner)->create();
    $underHiddenRoot = Thread::factory()->child($hiddenRoot)->create();

    $response = $this->getJson('/api/app/v1/threads?filter=everyone', phoneHeadersFor($viewer))->assertOk();
    $ids = collect($response->json('threads'))->flatMap(fn (array $row) => [$row['id'], ...array_column($row['children'], 'id')]);

    expect($ids)->toContain($shownChild->public_id)
        ->not->toContain($hiddenRoot->public_id)
        ->not->toContain($underHiddenRoot->public_id);
});

it('does not run more queries as sub-threads are added', function () {
    $user = User::factory()->create();
    $root = Thread::factory()->create(['created_by_user_id' => $user->id]);
    Thread::factory()->child($root)->count(2)->create(['created_by_user_id' => $user->id]);
    $headers = phoneHeadersFor($user);

    $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    DB::enableQueryLog();
    $this->getJson('/api/app/v1/threads', $headers)->assertOk();
    $withTwoChildren = count(DB::getQueryLog());

    $more = Thread::factory()->child($root)->count(4)->create(['created_by_user_id' => $user->id]);
    $more->each(fn (Thread $child) => startTurnFor($child, TurnState::Running));
    DB::flushQueryLog();
    $this->getJson('/api/app/v1/threads', $headers)->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withTwoChildren);
});
