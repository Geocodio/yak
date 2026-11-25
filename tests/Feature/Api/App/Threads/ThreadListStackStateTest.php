<?php

use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadKind;
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

    ContractFixture::assertMatches('app', 'threads-children', $response->json(), [
        'needsYou.*.id', 'starred.*.id', 'threads.*.id', 'threads.*.children.*.id', 'threads.*.starter.key', 'threads.*.children.*.starter.key', 'threads.*.lastActivityAt', 'threads.*.children.*.lastActivityAt',
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

it('runs the three state queries once per list, however many sub-threads there are, and none for closed ones', function () {
    $user = User::factory()->create();
    $headers = phoneHeadersFor($user);
    $stateQueries = function (string $query = '') use ($headers): array {
        $this->getJson('/api/app/v1/threads' . $query, $headers)->assertOk();
        DB::flushQueryLog();
        $this->getJson('/api/app/v1/threads' . $query, $headers)->assertOk();
        $log = collect(DB::getQueryLog())->pluck('query');

        return [
            'total' => $log->count(),
            'state' => $log->filter(fn (string $sql): bool => str_contains($sql, 'from "stack_thread_messages"') || (str_contains($sql, 'distinct "thread_id" from "task_runs"')))->count(),
        ];
    };
    DB::enableQueryLog();

    $root = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $withoutChildren = $stateQueries();
    Thread::factory()->child($root)->count(2)->create(['created_by_user_id' => $user->id]);
    $withTwo = $stateQueries();

    $more = Thread::factory()->child($root)->count(4)->create(['created_by_user_id' => $user->id]);
    $more->each(fn (Thread $child) => startTurnFor($child, TurnState::Running));
    $withSix = $stateQueries();

    expect($withoutChildren['state'])->toBe(0)
        ->and($withTwo['state'])->toBe(3)
        ->and($withSix)->toBe($withTwo);
});

it('runs no state queries for closed sub-threads', function () {
    $user = User::factory()->create();
    $root = Thread::factory()->closed()->create(['created_by_user_id' => $user->id]);
    Thread::factory()->child($root)->closed()->count(3)->create(['created_by_user_id' => $user->id]);
    DB::enableQueryLog();

    $response = $this->getJson('/api/app/v1/threads?filter=closed', phoneHeadersFor($user))->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    expect($response->json('threads.0.children'))->toHaveCount(3)
        ->and($response->json('threads.0.children.0.stackState'))->toBe('closed')
        ->and($sql)->not->toContain('from "stack_thread_messages"')
        ->and($sql)->not->toContain('distinct "thread_id" from "task_runs"');
});

it('reads a research sub-thread queued for a slot as starting', function () {
    $user = User::factory()->create();
    $root = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $research = Thread::factory()->child($root)->create(['kind' => ThreadKind::Research, 'queued_for_slot_at' => now(), 'created_by_user_id' => $user->id]);

    $row = collect($this->getJson('/api/app/v1/threads', phoneHeadersFor($user))->json('threads.0.children'))->firstWhere('id', $research->public_id);

    expect($row['stackState'])->toBe('starting');
});

it('gives a sub-thread the same keys in the star and close answers as in the list', function () {
    $user = User::factory()->create();
    $headers = phoneHeadersFor($user);
    $root = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $child = Thread::factory()->child($root)->create(['taken_over_at' => now(), 'created_by_user_id' => $user->id]);
    $inList = collect($this->getJson('/api/app/v1/threads', $headers)->json('threads.0.children'))->firstWhere('id', $child->public_id);

    $starred = $this->postJson(appThreadPath($child, '/star'), ['starred' => true], $headers)->assertOk();

    expect($starred->json('stackState'))->toBe($inList['stackState'])->toBe('starting')
        ->and($starred->json('takenOver'))->toBeTrue();

    $this->postJson(appThreadPath($child, '/close'), [], $headers)->assertOk()
        ->assertJsonPath('stackState', 'closed')
        ->assertJsonPath('takenOver', true);

    $this->postJson(appThreadPath($root, '/star'), ['starred' => true], $headers)->assertOk()->assertJsonMissingPath('stackState');
});
